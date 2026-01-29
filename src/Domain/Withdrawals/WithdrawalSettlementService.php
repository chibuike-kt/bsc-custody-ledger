<?php
declare(strict_types=1);

namespace App\Domain\Withdrawals;

use App\Domain\Ledger\LedgerService;
use App\Infrastructure\Db\Connection;
use App\Infrastructure\Db\TxRunner;
use Brick\Math\BigInteger;
use Ramsey\Uuid\Uuid;

final class WithdrawalSettlementService
{
  public function settleOnChainSuccess(array $withdrawRow): void
  {
    $pdo = Connection::pdo();
    $ledger = new LedgerService();

    TxRunner::run($pdo, function () use ($pdo, $ledger, $withdrawRow) {
      $id = (string)$withdrawRow['id'];
      $userId = (string)$withdrawRow['user_id'];
      $currency = (string)$withdrawRow['currency'];
      $amount = (string)$withdrawRow['amount_minor'];
      $holdRef = (string)$withdrawRow['hold_reference'];

      // lock withdrawal
      $s = $pdo->prepare("SELECT status FROM withdrawals WHERE id=? LIMIT 1 FOR UPDATE");
      $s->execute([$id]);
      $cur = $s->fetch();
      if (!$cur) return null;
      if ((string)$cur['status'] === 'settled') return null;
      if (!in_array((string)$cur['status'], ['broadcasted','confirming'], true)) return null;

      // journal idempotency by reference
      $journalRef = 'withdrawal:' . $id;
      $stmt = $pdo->prepare("SELECT id FROM journals WHERE reference=? LIMIT 1");
      $stmt->execute([$journalRef]);
      if ($stmt->fetch()) {
        $ledger->releaseHold($this->userAccountId($ledger, $userId, $currency), $holdRef);
        $u = $pdo->prepare("UPDATE withdrawals SET status='settled' WHERE id=?");
        $u->execute([$id]);
        return null;
      }

      $userAcctId = $this->userAccountId($ledger, $userId, $currency);
      $treasuryAcctId = $ledger->getOrCreateAccount('00000000-0000-0000-0000-000000000000', 'treasury', $currency);

      // lock accounts
      $ids = [$userAcctId, $treasuryAcctId];
      sort($ids);
      $stmt = $pdo->prepare("SELECT id FROM accounts WHERE id IN (?, ?) FOR UPDATE");
      $stmt->execute([$ids[0], $ids[1]]);

      // debit user spot (spent)
      $stmt = $pdo->prepare("SELECT balance_minor FROM accounts WHERE id=? LIMIT 1 FOR UPDATE");
      $stmt->execute([$userAcctId]);
      $ub = $stmt->fetch();
      $userBal = $ub ? BigInteger::of((string)$ub['balance_minor']) : BigInteger::zero();

      $amt = BigInteger::of($amount);
      if ($userBal->isLessThan($amt)) {
        // Should not happen if holds were respected; if it does, freeze by leaving hold active and flag.
        throw new \DomainException('user_balance_inconsistent');
      }

      $journalId = Uuid::uuid4()->toString();
      $stmt = $pdo->prepare("INSERT INTO journals (id, type, reference, status) VALUES (?, 'crypto_withdrawal', ?, 'posted')");
      $stmt->execute([$journalId, $journalRef]);

      // posting debit user
      $stmt = $pdo->prepare("INSERT INTO postings (id, journal_id, account_id, direction, amount_minor, currency) VALUES (?, ?, ?, 'debit', ?, ?)");
      $stmt->execute([Uuid::uuid4()->toString(), $journalId, $userAcctId, $amount, $currency]);

      // posting credit treasury (outflow tracking)
      $stmt = $pdo->prepare("INSERT INTO postings (id, journal_id, account_id, direction, amount_minor, currency) VALUES (?, ?, ?, 'credit', ?, ?)");
      $stmt->execute([Uuid::uuid4()->toString(), $journalId, $treasuryAcctId, $amount, $currency]);

      // update cached balances
      $stmt = $pdo->prepare("UPDATE accounts SET balance_minor=? WHERE id=?");
      $stmt->execute([$userBal->minus($amt)->toBase(10), $userAcctId]);

      $stmt = $pdo->prepare("SELECT balance_minor FROM accounts WHERE id=? LIMIT 1 FOR UPDATE");
      $stmt->execute([$treasuryAcctId]);
      $tb = $stmt->fetch();
      $tBal = $tb ? BigInteger::of((string)$tb['balance_minor']) : BigInteger::zero();

      $stmt = $pdo->prepare("UPDATE accounts SET balance_minor=? WHERE id=?");
      $stmt->execute([$tBal->plus($amt)->toBase(10), $treasuryAcctId]);

      // release hold now that debit is posted
      $ledger->releaseHold($userAcctId, $holdRef);

      $u = $pdo->prepare("UPDATE withdrawals SET status='settled' WHERE id=?");
      $u->execute([$id]);

      return null;
    }, 3);
  }

  private function userAccountId(LedgerService $ledger, string $userId, string $currency): string
  {
    return $ledger->getOrCreateAccount($userId, 'spot', $currency);
  }
}
