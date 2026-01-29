<?php
declare(strict_types=1);

namespace App\Domain\Withdrawals\Commands;

use App\Infrastructure\Db\Connection;
use App\Infrastructure\Db\TxRunner;
use App\Infrastructure\Rpc\JsonRpcClient;
use App\Infrastructure\Rpc\Evm;
use App\Domain\Withdrawals\Erc20;
use App\Domain\Withdrawals\NonceService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Web3p\EthereumTx\Transaction;

final class BroadcastWithdrawalsCommand extends Command
{
  protected static $defaultName = 'withdrawals:broadcast';

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $chain = 'bsc';

    $hotAddr = (string)getenv('DEV_HOTWALLET_ADDRESS');
    $hotKey  = (string)getenv('DEV_HOTWALLET_PRIVATE_KEY');
    if ($hotAddr === '' || $hotKey === '') {
      $output->writeln('missing DEV_HOTWALLET_ADDRESS or DEV_HOTWALLET_PRIVATE_KEY');
      return Command::FAILURE;
    }

    $hotAddr = Evm::normalizeAddress($hotAddr);

    $rpc = new JsonRpcClient((string)getenv('BSC_RPC_URL'));
    $pdo = Connection::pdo();
    $nonceSvc = new NonceService();

    $usdt = strtolower((string)getenv('BSC_USDT_CONTRACT'));
    $chainId = (int)(getenv('BSC_CHAIN_ID') ?: 56);
    $gasLimit = (int)(getenv('BSC_USDT_TRANSFER_GAS') ?: 120000);

    $stmt = $pdo->prepare("
      SELECT id, to_address, amount_minor, status
      FROM withdrawals
      WHERE chain='bsc' AND status IN ('created','retry_broadcast')
      ORDER BY created_at ASC
      LIMIT 20
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $processed = 0;

    foreach ($rows as $w) {
      $withdrawId = (string)$w['id'];
      $to = (string)$w['to_address'];
      $amt = (string)$w['amount_minor'];

      try {
        $txHash = TxRunner::run($pdo, function () use ($pdo, $rpc, $nonceSvc, $withdrawId, $to, $amt, $hotAddr, $hotKey, $usdt, $chainId, $gasLimit) {
          // lock withdrawal row
          $s = $pdo->prepare("SELECT status, tx_hash FROM withdrawals WHERE id=? LIMIT 1 FOR UPDATE");
          $s->execute([$withdrawId]);
          $cur = $s->fetch();
          if (!$cur) return null;

          $status = (string)$cur['status'];
          if (!in_array($status, ['created','retry_broadcast'], true)) return null;

          if (!empty($cur['tx_hash'])) {
            return (string)$cur['tx_hash']; // idempotent
          }

          // gas price (simple; later we can add EIP-1559 style on chains that support it)
          $gasPriceHex = (string)$rpc->call('eth_gasPrice');
          $gasPriceDec = Evm::hexToDec($gasPriceHex);

          // allocate nonce (race-safe)
          $nonce = $nonceSvc->nextNonce('bsc', $hotAddr);

          $data = \App\Domain\Withdrawals\Erc20::encodeTransfer($to, $amt);

          $txParams = [
            'nonce'    => Evm::decToHex((string)$nonce),
            'gasPrice' => Evm::decToHex($gasPriceDec),
            'gas'      => Evm::decToHex((string)$gasLimit),
            'to'       => $usdt,
            'value'    => '0x0',
            'data'     => $data,
            'chainId'  => $chainId
          ];

          $tx = new Transaction($txParams);
          $raw = '0x' . $tx->sign($hotKey);

          // mark signing/broadcasting
          $u = $pdo->prepare("UPDATE withdrawals SET status='broadcasting', nonce=? WHERE id=?");
          $u->execute([$nonce, $withdrawId]);

          // broadcast
          $hash = (string)$rpc->call('eth_sendRawTransaction', [$raw]);

          $u = $pdo->prepare("UPDATE withdrawals SET status='broadcasted', tx_hash=?, error_code=NULL WHERE id=?");
          $u->execute([strtolower($hash), $withdrawId]);

          return strtolower($hash);
        }, 3);

        if ($txHash) $processed++;
      } catch (\Throwable $e) {
        // mark retryable
        try {
          $msg = substr($e->getMessage(), 0, 120);
          $u = $pdo->prepare("UPDATE withdrawals SET status='retry_broadcast', error_code=? WHERE id=?");
          $u->execute([$msg, $withdrawId]);
        } catch (\Throwable $ignore) {}
      }
    }

    $output->writeln("checked=" . count($rows) . " processed={$processed}");
    return Command::SUCCESS;
  }
}
