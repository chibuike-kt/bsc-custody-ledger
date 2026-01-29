<?php
declare(strict_types=1);

namespace App\Domain\Deposits\Commands;

use App\Domain\Ledger\LedgerService;
use App\Infrastructure\Db\Connection;
use App\Infrastructure\Db\TxRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ResolveFrozenDepositsCommand extends Command
{
  protected static $defaultName = 'deposits:resolve-frozen';

  protected function configure(): void
  {
    $this
      ->addOption('action', null, InputOption::VALUE_REQUIRED, 'release|clawback')
      ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'max rows', '50');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $action = (string)$input->getOption('action');
    if (!in_array($action, ['release', 'clawback'], true)) {
      $output->writeln("invalid --action (use release|clawback)");
      return Command::FAILURE;
    }

    $limit = (int)$input->getOption('limit');

    $pdo = Connection::pdo();
    $ledger = new LedgerService();

    $stmt = $pdo->prepare("
      SELECT id, to_address, amount_raw, external_ref
      FROM chain_deposits
      WHERE chain='bsc' AND status='frozen_review'
      ORDER BY block_number DESC
      LIMIT ?
    ");
    $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $done = 0;

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

      // accounts
      $userAcctId = $ledger->getOrCreateAccount($userId, 'spot', 'USDT.BSC');
      $treasuryUserId = '00000000-0000-0000-0000-000000000000';
      $treasuryAcctId = $ledger->getOrCreateAccount($treasuryUserId, 'treasury', 'USDT.BSC');

      // all changes atomically
      TxRunner::run($pdo, function () use ($pdo, $ledger, $action, $depositId, $externalRef, $amountRaw, $userAcctId, $treasuryAcctId) {
        // lock deposit row to avoid double resolve
        $s = $pdo->prepare("SELECT status FROM chain_deposits WHERE id=? LIMIT 1 FOR UPDATE");
        $s->execute([$depositId]);
        $cur = $s->fetch();
        if (!$cur) return null;
        if ((string)$cur['status'] !== 'frozen_review') return null;

        if ($action === 'release') {
          $ledger->releaseHold($userAcctId, $externalRef);

          $u = $pdo->prepare("UPDATE chain_deposits SET status='credited' WHERE id=? AND status='frozen_review'");
          $u->execute([$depositId]);
          return null;
        }

        // clawback
        $ledger->clawbackDeposit($userAcctId, $treasuryAcctId, 'USDT.BSC', $amountRaw, $externalRef);
        $ledger->releaseHold($userAcctId, $externalRef);

        $u = $pdo->prepare("UPDATE chain_deposits SET status='clawed_back' WHERE id=? AND status='frozen_review'");
        $u->execute([$depositId]);
        return null;
      }, 3);

      $done++;
    }

    $output->writeln("action={$action} checked=" . count($rows) . " processed={$done}");
    return Command::SUCCESS;
  }
}
