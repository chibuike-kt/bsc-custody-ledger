<?php
declare(strict_types=1);

namespace App\Domain\Withdrawals\Commands;

use App\Infrastructure\Db\Connection;
use App\Infrastructure\Rpc\JsonRpcClient;
use App\Domain\Withdrawals\WithdrawalSettlementService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ConfirmWithdrawalsCommand extends Command
{
  protected static $defaultName = 'withdrawals:confirm';

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $rpc = new JsonRpcClient((string)getenv('BSC_RPC_URL'));
    $pdo = Connection::pdo();

    $stmt = $pdo->prepare("
      SELECT id, user_id, currency, amount_minor, hold_reference, tx_hash, status
      FROM withdrawals
      WHERE chain='bsc' AND status IN ('broadcasted','confirming')
      ORDER BY updated_at ASC
      LIMIT 30
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $settled = 0;
    $checked = 0;
    $settler = new WithdrawalSettlementService();

    foreach ($rows as $w) {
      $checked++;
      $tx = (string)$w['tx_hash'];
      if ($tx === '') continue;

      $receipt = $rpc->call('eth_getTransactionReceipt', [$tx]);

      if (!$receipt || !is_array($receipt)) {
        // still pending, keep confirming state
        $u = $pdo->prepare("UPDATE withdrawals SET status='confirming' WHERE id=? AND status='broadcasted'");
        $u->execute([(string)$w['id']]);
        continue;
      }

      // status can be 0x1 or 0x0
      $ok = strtolower((string)($receipt['status'] ?? '0x0')) === '0x1';

      if ($ok) {
        $settler->settleOnChainSuccess($w);
        $settled++;
      } else {
        // on-chain failed: release hold and mark failed
        try {
          $pdo->beginTransaction();
          $s = $pdo->prepare("SELECT status FROM withdrawals WHERE id=? LIMIT 1 FOR UPDATE");
          $s->execute([(string)$w['id']]);
          $cur = $s->fetch();
          if ($cur && (string)$cur['status'] !== 'failed') {
            // release hold (funds become available again)
            $ledger = new \App\Domain\Ledger\LedgerService();
            $acctId = $ledger->getOrCreateAccount((string)$w['user_id'], 'spot', (string)$w['currency']);
            $ledger->releaseHold($acctId, (string)$w['hold_reference']);

            $u = $pdo->prepare("UPDATE withdrawals SET status='failed', error_code='tx_failed' WHERE id=?");
            $u->execute([(string)$w['id']]);
          }
          $pdo->commit();
        } catch (\Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
        }
      }
    }

    $output->writeln("checked={$checked} settled={$settled}");
    return Command::SUCCESS;
  }
}
