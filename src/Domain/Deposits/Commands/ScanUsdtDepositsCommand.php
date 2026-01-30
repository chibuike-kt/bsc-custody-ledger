<?php
declare(strict_types=1);

namespace App\Domain\Deposits\Commands;

use App\Infrastructure\Db\Connection;
use App\Infrastructure\Rpc\JsonRpcClient;
use Brick\Math\BigInteger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ScanUsdtDepositsCommand extends Command
{
  protected static $defaultName = 'deposits:scan-usdt';

  // keccak256("Transfer(address,address,uint256)")
  private const TRANSFER_TOPIC0 = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $rpcUrl = (string)getenv('BSC_RPC_URL');
    $token  = strtolower((string)getenv('BSC_USDT_CONTRACT'));

    $rpc = new JsonRpcClient($rpcUrl);
    $pdo = Connection::pdo();

    // load all active deposit addresses
    $stmt = $pdo->prepare("SELECT address FROM wallet_addresses WHERE chain = 'bsc' AND active = 1");
    $stmt->execute();
    $addresses = array_map(fn($r) => strtolower((string)$r['address']), $stmt->fetchAll());

    if (!$addresses) {
      $output->writeln('no deposit addresses yet');
      return Command::SUCCESS;
    }

    $headHex = (string)$rpc->call('eth_blockNumber');
    $head = (int)BigInteger::fromBase(ltrim(strtolower($headHex), '0x'), 16)->toBase(10);

    // cursor key for this scanner
    $cursorKey = 'bsc_usdt_scan_block';

    // read cursor (default to head-5000 for first run so you can catch recent history)
    $stmt = $pdo->prepare("SELECT cursor_value FROM chain_cursors WHERE chain='bsc' AND cursor_key=? LIMIT 1");
    $stmt->execute([$cursorKey]);
    $row = $stmt->fetch();
    $cursor = $row ? (int)$row['cursor_value'] : max(0, $head - 5000);

    // chunk size per run (tune as your RPC allows)
    $chunk = 1200;
    $from = min($head, $cursor + 1);
    $to   = min($head, $from + $chunk);

    if ($from > $to) {
      $output->writeln("head={$head} cursor={$cursor} nothing_to_scan");
      return Command::SUCCESS;
    }

    // topic[2] is "to" (indexed), left-padded 32 bytes
    $topicsTo = [];
    foreach ($addresses as $a) {
      $topicsTo[] = '0x' . str_pad(substr($a, 2), 64, '0', STR_PAD_LEFT);
    }

    $filter = [
      'fromBlock' => '0x' . dechex($from),
      'toBlock'   => '0x' . dechex($to),
      'address'   => $token,
      'topics'    => [
        self::TRANSFER_TOPIC0,
        null,
        $topicsTo
      ],
    ];

    $logs = $rpc->call('eth_getLogs', [$filter]);
    if (!is_array($logs)) $logs = [];

    $inserted = 0;

    foreach ($logs as $log) {
      $txHash = strtolower((string)($log['transactionHash'] ?? ''));
      $logIndexHex = (string)($log['logIndex'] ?? '0x0');
      $logIndex = (int)BigInteger::fromBase(ltrim(strtolower($logIndexHex), '0x'), 16)->toBase(10);

      $blockHex = (string)($log['blockNumber'] ?? '0x0');
      $blockNum = (int)BigInteger::fromBase(ltrim(strtolower($blockHex), '0x'), 16)->toBase(10);

      $blockHash = strtolower((string)($log['blockHash'] ?? ''));

      $topics = $log['topics'] ?? [];
      if (!is_array($topics) || count($topics) < 3) continue;

      $fromTopic = strtolower((string)$topics[1]);
      $toTopic   = strtolower((string)$topics[2]);

      $fromAddr = '0x' . substr($fromTopic, 26);
      $toAddr   = '0x' . substr($toTopic, 26);

      $amountHex = (string)($log['data'] ?? '0x0');
      $hex = ltrim(strtolower($amountHex), '0x');
      if ($hex === '') $hex = '0';
      $amountRaw = BigInteger::fromBase($hex, 16)->toBase(10);

      $externalRef = 'bsc:usdt:' . $txHash . ':' . $logIndex;

      try {
        $stmt = $pdo->prepare("
          INSERT INTO chain_deposits
            (id, chain, token_contract, tx_hash, log_index, from_address, to_address, amount_raw, block_number, block_hash, status, external_ref)
          VALUES
            (?, 'bsc', ?, ?, ?, ?, ?, ?, ?, ?, 'detected', ?)
        ");
        $stmt->execute([
          Uuid::uuid4()->toString(),
          $token,
          $txHash,
          $logIndex,
          $fromAddr,
          $toAddr,
          $amountRaw,
          $blockNum,
          $blockHash !== '' ? $blockHash : null,
          $externalRef,
        ]);
        $inserted++;
      } catch (\Throwable $e) {
        // duplicates expected
      }
    }

    // advance cursor safely to "to"
    $pdo->beginTransaction();
    try {
      $stmt = $pdo->prepare("SELECT id FROM chain_cursors WHERE chain='bsc' AND cursor_key=? LIMIT 1 FOR UPDATE");
      $stmt->execute([$cursorKey]);
      $curRow = $stmt->fetch();

      if ($curRow) {
        $u = $pdo->prepare("UPDATE chain_cursors SET cursor_value=? WHERE chain='bsc' AND cursor_key=?");
        $u->execute([$to, $cursorKey]);
      } else {
        $i = $pdo->prepare("INSERT INTO chain_cursors (id, chain, cursor_key, cursor_value) VALUES (?, 'bsc', ?, ?)");
        $i->execute([Uuid::uuid4()->toString(), $cursorKey, $to]);
      }

      $pdo->commit();
    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }

    $output->writeln("head={$head} cursor={$cursor} scanned={$from}-{$to} logs=" . count($logs) . " inserted={$inserted}");
    return Command::SUCCESS;
  }
  
    }

