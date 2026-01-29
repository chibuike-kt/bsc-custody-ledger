<?php
declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Infrastructure\Db\Connection;
use App\Infrastructure\Db\TxRunner;
use Brick\Math\BigInteger;
use Ramsey\Uuid\Uuid;

final class LedgerService
{
  public function creditDeposit(string $userId, string $currency, string $amountMinor, string $externalRef): string
  {
    $pdo = Connection::pdo();

    return TxRunner::run($pdo, function() use ($pdo, $userId, $currency, $amountMinor, $externalRef) {
      // idempotency: journal.reference is unique
      $stmt = $pdo->prepare("SELECT id FROM journals WHERE reference = ? LIMIT 1");
      $stmt->execute([$externalRef]);
      $existing = $stmt->fetch();
      if ($existing) return (string)$existing['id'];

      // ensure account exists
      $stmt = $pdo->prepare("SELECT id, balance_minor FROM accounts WHERE user_id=? AND type='spot' AND currency=? LIMIT 1 FOR UPDATE");
      $stmt->execute([$userId, $currency]);
      $acct = $stmt->fetch();

      if (!$acct) {
        $acctId = Uuid::uuid4()->toString();
        $stmt = $pdo->prepare("INSERT INTO accounts (id, user_id, type, currency, balance_minor) VALUES (?, ?, 'spot', ?, '0')");
        $stmt->execute([$acctId, $userId, $currency]);

        $balance = BigInteger::zero();
      } else {
        $acctId = (string)$acct['id'];
        $balance = BigInteger::of((string)$acct['balance_minor']);
      }

      $amt = BigInteger::of($amountMinor);

      // journal
      $journalId = Uuid::uuid4()->toString();
      $stmt = $pdo->prepare("INSERT INTO journals (id, type, reference, status) VALUES (?, 'crypto_deposit', ?, 'posted')");
      $stmt->execute([$journalId, $externalRef]);

      // posting: credit user
      $stmt = $pdo->prepare("
        INSERT INTO postings (id, journal_id, account_id, direction, amount_minor, currency)
        VALUES (?, ?, ?, 'credit', ?, ?)
      ");
      $stmt->execute([Uuid::uuid4()->toString(), $journalId, $acctId, $amountMinor, $currency]);

      // update cached balance
      $newBal = $balance->plus($amt)->toBase(10);
      $stmt = $pdo->prepare("UPDATE accounts SET balance_minor=? WHERE id=?");
      $stmt->execute([$newBal, $acctId]);

      return $journalId;
    }, 3);
  }
}
