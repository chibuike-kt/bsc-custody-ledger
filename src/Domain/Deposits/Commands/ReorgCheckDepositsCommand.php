<?php
declare(strict_types=1);

namespace App\Domain\Deposits\Commands;

use App\Infrastructure\Db\Connection;
use App\Infrastructure\Rpc\JsonRpcClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ReorgCheckDepositsCommand extends Command
{
  protected static $defaultName = 'deposits:reorg-check';

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $rpcUrl = (string)getenv('BSC_RPC_URL');
    $rpc = new JsonRpcClient($rpcUrl);
    $pdo = Connection::pdo();

    // only check recent blocks to avoid heavy load (tune)
    $reorgWindowBlocks = 400;

    $headHex = (string)$rpc->call('eth_blockNumber');
    $head = hexdec($headHex);

    $minBlock = max(0, $head - $reorgWindowBlocks);

    $stmt = $pdo->prepare("
      SELECT id, tx_hash, block_hash, status
      FROM chain_deposits
      WHERE chain='bsc'
        AND block_number >= ?
        AND status IN ('detected','confirming','confirmed','credited')
      ORDER BY block_number DESC
      LIMIT 300
    ");
    $stmt->execute([$minBlock]);
    $rows = $stmt->fetchAll();

    $checked = 0;
    $orphaned = 0;

    foreach ($rows as $r) {
      $checked++;
      $id = (string)$r['id'];
      $tx = strtolower((string)$r['tx_hash']);
      $storedBlockHash = strtolower((string)($r['block_hash'] ?? ''));
      $status = (string)$r['status'];

      // If receipt is missing, tx may be dropped/reorged or RPC has issues.
      $receipt = $rpc->call('eth_getTransactionReceipt', [$tx]);

      if (!$receipt || !is_array($receipt)) {
        $this->markOrphaned($pdo, $id, $status, 'missing_receipt');
        $orphaned++;
        continue;
      }

      $receiptBlockHash = strtolower((string)($receipt['blockHash'] ?? ''));
      if ($storedBlockHash !== '' && $receiptBlockHash !== '' && $storedBlockHash !== $receiptBlockHash) {
        $this->markOrphaned($pdo, $id, $status, 'block_hash_mismatch');
        $orphaned++;
        continue;
      }
    }

    $output->writeln("head={$head} checked={$checked} orphaned={$orphaned}");
    return Command::SUCCESS;
  }

  private function markOrphaned(\PDO $pdo, string $depositId, string $prevStatus, string $reason): void
  {
    // Never auto-reverse credited funds here (that’s a separate controlled flow).
    // We mark for review to avoid creating an unsafe auto-debit path.
    $next = ($prevStatus === 'credited') ? 'orphaned_review' : 'orphaned';

    $stmt = $pdo->prepare("
      UPDATE chain_deposits
      SET status=?, orphaned_at=CURRENT_TIMESTAMP, orphan_reason=?
      WHERE id=? AND status=?
    ");
    $stmt->execute([$next, $reason, $depositId, $prevStatus]);
  }
}
