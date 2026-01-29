<?php
declare(strict_types=1);

namespace App\Domain\Deposits\Commands;

use App\Infrastructure\Db\Connection;
use App\Infrastructure\Rpc\JsonRpcClient;
use Brick\Math\BigInteger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ConfirmDepositsCommand extends Command
{
  protected static $defaultName = 'deposits:confirm';

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $rpcUrl = (string)getenv('BSC_RPC_URL');
    $confirmationsReq = (int)(getenv('DEPOSIT_CONFIRMATIONS') ?: 15);

    $rpc = new JsonRpcClient($rpcUrl);
    $pdo = Connection::pdo();

    $headHex = (string)$rpc->call('eth_blockNumber');
    $head = (int)BigInteger::fromBase(ltrim(strtolower($headHex), '0x'), 16)->toBase(10);

    $stmt = $pdo->prepare("
      SELECT id, block_number
      FROM chain_deposits
      WHERE chain='bsc' AND status IN ('detected','confirming')
      ORDER BY block_number ASC
      LIMIT 500
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $updated = 0;

    foreach ($rows as $r) {
      $id = (string)$r['id'];
      $bn = (int)$r['block_number'];

      $conf = max(0, ($head - $bn) + 1);

      if ($conf >= $confirmationsReq) {
        $u = $pdo->prepare("
          UPDATE chain_deposits
          SET status='confirmed', confirmations=?, confirmed_at=COALESCE(confirmed_at, CURRENT_TIMESTAMP)
          WHERE id=? AND status IN ('detected','confirming')
        ");
        $u->execute([$conf, $id]);
        $updated += $u->rowCount();
      } else {
        $u = $pdo->prepare("
          UPDATE chain_deposits
          SET status='confirming', confirmations=?
          WHERE id=? AND status='detected'
        ");
        $u->execute([$conf, $id]);
        $updated += $u->rowCount();
      }
    }

    $output->writeln("head={$head} checked=" . count($rows) . " updated={$updated}");
    return Command::SUCCESS;
  }
}
