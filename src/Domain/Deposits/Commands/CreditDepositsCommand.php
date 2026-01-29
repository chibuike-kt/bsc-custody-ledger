<?php
declare(strict_types=1);

namespace App\Domain\Deposits\Commands;

use App\Domain\Ledger\LedgerService;
use App\Infrastructure\Db\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CreditDepositsCommand extends Command
{
  protected static $defaultName = 'deposits:credit';

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $pdo = Connection::pdo();

    $stmt = $pdo->prepare("
      SELECT d.id, d.to_address, d.amount_raw, d.external_ref
      FROM chain_deposits d
      WHERE d.chain='bsc' AND d.status='confirmed'
      ORDER BY d.block_number ASC
      LIMIT 200
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $credited = 0;
    $svc = new LedgerService();

    foreach ($rows as $r) {
      $depositId = (string)$r['id'];
      $to = strtolower((string)$r['to_address']);
      $amountRaw = (string)$r['amount_raw'];
      $externalRef = (string)$r['external_ref'];

      // map deposit address -> user
      $m = $pdo->prepare("SELECT user_id FROM wallet_addresses WHERE chain='bsc' AND LOWER(address)=? LIMIT 1");
      $m->execute([$to]);
      $u = $m->fetch();
      if (!$u) {
        // keep it confirmed but uncredited; you can alert on this later
        continue;
      }
      $userId = (string)$u['user_id'];

      // credit ledger idempotently
      $journalId = $svc->creditDeposit($userId, 'USDT.BSC', $amountRaw, $externalRef);

      // mark deposit credited (idempotent)
      $upd = $pdo->prepare("
        UPDATE chain_deposits
        SET status='credited', credited_at=COALESCE(credited_at, CURRENT_TIMESTAMP), ledger_journal_id=?
        WHERE id=? AND status='confirmed'
      ");
      $upd->execute([$journalId, $depositId]);

      $credited += $upd->rowCount();
    }

    $output->writeln("checked=" . count($rows) . " credited={$credited}");
    return Command::SUCCESS;
  }
}
