<?php
declare(strict_types=1);

namespace App\Domain\Deposits\Commands;

use App\Domain\Ledger\LedgerService;
use App\Infrastructure\Db\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class FreezeOrphanedDepositsCommand extends Command
{
  protected static $defaultName = 'deposits:freeze-orphaned';

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $pdo = Connection::pdo();
    $ledger = new LedgerService();

    $stmt = $pdo->prepare("
      SELECT id, to_address, amount_raw, external_ref
      FROM chain_deposits
      WHERE chain='bsc' AND status='orphaned_review'
      ORDER BY block_number DESC
      LIMIT 200
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $frozen = 0;

    foreach ($rows as $r) {
      $depositId = (string)$r['id'];
      $to = strtolower((string)$r['to_address']);
      $amountRaw = (string)$r['amount_raw'];
      $externalRef = (string)$r['external_ref'];

      $m = $pdo->prepare("SELECT user_id FROM wallet_addresses WHERE chain='bsc' AND LOWER(address)=? LIMIT 1");
      $m->execute([$to]);
      $u = $m->fetch();
      if (!$u) continue;

      $userId = (string)$u['user_id'];

      // ensure user spot account exists
      $acctId = $ledger->getOrCreateAccount($userId, 'spot', 'USDT.BSC');

      // place hold idempotently
      $ledger->placeHold($acctId, $externalRef, $amountRaw, 'orphaned_deposit_review');

      // mark deposit frozen_review (idempotent)
      $upd = $pdo->prepare("
        UPDATE chain_deposits
        SET status='frozen_review'
        WHERE id=? AND status='orphaned_review'
      ");
      $upd->execute([$depositId]);
      $frozen += $upd->rowCount();
    }

    $output->writeln("checked=" . count($rows) . " frozen={$frozen}");
    return Command::SUCCESS;
  }
}
