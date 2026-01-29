<?php
declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Infrastructure\Db\Connection;
use App\Infrastructure\Db\TxRunner;
use Brick\Math\BigInteger;
use Ramsey\Uuid\Uuid;

final class LedgerService
{
  public function getOrCreateAccount(string $userId, string $type, string $currency): string
  {
    $pdo = Connection::pdo();

    return TxRunner::run($pdo, function () use ($pdo, $userId, $type, $currency) {
      $stmt = $pdo->prepare("SELECT id FROM accounts WHERE user_id=? AND type=? AND currency=? LIMIT 1");
      $stmt->execute([$userId, $type, $currency]);
      $row = $stmt->fetch();
      if ($row) return (string)$row['id'];

      $id = Uuid::uuid4()->toString();
      $stmt = $pdo->prepare("INSERT INTO accounts (id, user_id, type, currency, balance_minor) VALUES (?, ?, ?, ?, '0')");
      $stmt->execute([$id, $userId, $type, $currency]);
      return $id;
    }, 3);
  }

  public function creditDeposit(string $userId, string $currency, string $amountMinor, string $externalRef): string
  {
    $pdo = Connection::pdo();

    return TxRunner::run($pdo, function() use ($pdo, $userId, $currency, $amountMinor, $externalRef) {
      // idempotency: journal.reference unique
      $stmt = $pdo->prepare("SELECT id FROM journals WHERE reference = ? LIMIT 1");
      $stmt->execute([$externalRef]);
      $existing = $stmt->fetch();
      if ($existing) return (string)$existing['id'];

      // lock account
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

      // posting credit user
      $stmt = $pdo->prepare("
        INSERT INTO postings (id, journal_id, account_id, direction, amount_minor, currency)
        VALUES (?, ?, ?, 'credit', ?, ?)
      ");
      $stmt->execute([Uuid::uuid4()->toString(), $journalId, $acctId, $amountMinor, $currency]);

      // cached balance
      $newBal = $balance->plus($amt)->toBase(10);
      $stmt = $pdo->prepare("UPDATE accounts SET balance_minor=? WHERE id=?");
      $stmt->execute([$newBal, $acctId]);

      return $journalId;
    }, 3);
  }

  public function activeHoldsTotal(string $accountId): string
  {
    $pdo = Connection::pdo();
    $stmt = $pdo->prepare("SELECT amount_minor FROM account_holds WHERE account_id=? AND status='active'");
    $stmt->execute([$accountId]);
    $rows = $stmt->fetchAll();

    $sum = BigInteger::zero();
    foreach ($rows as $r) {
      $sum = $sum->plus(BigInteger::of((string)$r['amount_minor']));
    }
    return $sum->toBase(10);
  }

  public function placeHold(string $accountId, string $reference, string $amountMinor, string $reason): void
  {
    $pdo = Connection::pdo();

    TxRunner::run($pdo, function () use ($pdo, $accountId, $reference, $amountMinor, $reason) {
      // idempotent due to uniq_hold_account_ref
      $stmt = $pdo->prepare("SELECT id FROM account_holds WHERE account_id=? AND reference=? LIMIT 1");
      $stmt->execute([$accountId, $reference]);
      if ($stmt->fetch()) return;

      $stmt = $pdo->prepare("
        INSERT INTO account_holds (id, account_id, reference, amount_minor, reason, status)
        VALUES (?, ?, ?, ?, ?, 'active')
      ");
      $stmt->execute([Uuid::uuid4()->toString(), $accountId, $reference, $amountMinor, $reason]);

      return null;
    }, 3);
  }

  public function releaseHold(string $accountId, string $reference): void
  {
    $pdo = Connection::pdo();

    TxRunner::run($pdo, function () use ($pdo, $accountId, $reference) {
      $stmt = $pdo->prepare("
        UPDATE account_holds
        SET status='released', released_at=CURRENT_TIMESTAMP
        WHERE account_id=? AND reference=? AND status='active'
      ");
      $stmt->execute([$accountId, $reference]);
      return null;
    }, 3);
  }

  /**
   * Controlled clawback:
   * - Debit user spot account
   * - Credit treasury account
   * - Release hold (if present)
   *
   * This is manual because auto-debiting on reorg signals can be dangerous.
   */
  public function clawbackDeposit(
    string $userAccountId,
    string $treasuryAccountId,
    string $currency,
    string $amountMinor,
    string $externalRef
  ): string {
    $pdo = Connection::pdo();
    $journalRef = 'clawback:' . $externalRef;

    return TxRunner::run($pdo, function () use ($pdo, $userAccountId, $treasuryAccountId, $currency, $amountMinor, $externalRef, $journalRef) {
      $stmt = $pdo->prepare("SELECT id FROM journals WHERE reference=? LIMIT 1");
      $stmt->execute([$journalRef]);
      $existing = $stmt->fetch();
      if ($existing) return (string)$existing['id'];

      // lock both accounts
      $ids = [$userAccountId, $treasuryAccountId];
      sort($ids);
      $stmt = $pdo->prepare("SELECT id FROM accounts WHERE id IN (?, ?) FOR UPDATE");
      $stmt->execute([$ids[0], $ids[1]]);
      $rows = $stmt->fetchAll();
      if (count($rows) !== 2) throw new \DomainException('account_not_found');

      // lock and check user balance
      $stmt = $pdo->prepare("SELECT balance_minor FROM accounts WHERE id=? LIMIT 1 FOR UPDATE");
      $stmt->execute([$userAccountId]);
      $ub = $stmt->fetch();
      if (!$ub) throw new \DomainException('user_account_not_found');

      $userBal = BigInteger::of((string)$ub['balance_minor']);
      $amt = BigInteger::of($amountMinor);

      if ($userBal->isLessThan($amt)) throw new \DomainException('insufficient_funds_for_clawback');

      // journal
      $journalId = Uuid::uuid4()->toString();
      $stmt = $pdo->prepare("INSERT INTO journals (id, type, reference, status) VALUES (?, 'deposit_clawback', ?, 'posted')");
      $stmt->execute([$journalId, $journalRef]);

      // debit user
      $stmt = $pdo->prepare("
        INSERT INTO postings (id, journal_id, account_id, direction, amount_minor, currency)
        VALUES (?, ?, ?, 'debit', ?, ?)
      ");
      $stmt->execute([Uuid::uuid4()->toString(), $journalId, $userAccountId, $amountMinor, $currency]);

      // credit treasury
      $stmt = $pdo->prepare("
        INSERT INTO postings (id, journal_id, account_id, direction, amount_minor, currency)
        VALUES (?, ?, ?, 'credit', ?, ?)
      ");
      $stmt->execute([Uuid::uuid4()->toString(), $journalId, $treasuryAccountId, $amountMinor, $currency]);

      // update cached balances
      $stmt = $pdo->prepare("UPDATE accounts SET balance_minor=? WHERE id=?");
      $stmt->execute([$userBal->minus($amt)->toBase(10), $userAccountId]);

      $stmt = $pdo->prepare("SELECT balance_minor FROM accounts WHERE id=? LIMIT 1 FOR UPDATE");
      $stmt->execute([$treasuryAccountId]);
      $tb = $stmt->fetch();
      $treasuryBal = $tb ? BigInteger::of((string)$tb['balance_minor']) : BigInteger::zero();

      $stmt = $pdo->prepare("UPDATE accounts SET balance_minor=? WHERE id=?");
      $stmt->execute([$treasuryBal->plus($amt)->toBase(10), $treasuryAccountId]);

      return $journalId;
    }, 3);
  }
  public function availableBalance(string $accountId): string
  {
    $pdo = \App\Infrastructure\Db\Connection::pdo();   
  $stmt = $pdo->prepare("SELECT balance_minor FROM accounts WHERE id=? LIMIT 1");    
  $stmt->execute([$accountId]);    
  $row = $stmt->fetch();    
  $bal = $row ? \Brick\Math\BigInteger::of((string)$row["balance_minor"]) : \Brick\Math\BigInteger::zero();  
  $held = \Brick\Math\BigInteger::of($this->activeHoldsTotal($accountId));    
  $avail = $bal->minus($held);    
  if ($avail->isLessThan(\Brick\Math\BigInteger::zero())) $avail = \Brick\Math\BigInteger::zero();    
  return $avail->toBase(10);  
  } 

  public function reserveForWithdrawal(string $accountId, string $holdRef, string $amountMinor): void  
  {    
    $avail = \Brick\Math\BigInteger::of($this->availableBalance($accountId));    
    $amt = \Brick\Math\BigInteger::of($amountMinor);    
    if ($amt->isLessThanOrEqualTo(\Brick\Math\BigInteger::zero())) 
      throw new \DomainException("invalid_amount");    
    if ($avail->isLessThan($amt)) 
      throw new \DomainException("insufficient_available_balance");    
    $this->placeHold($accountId, $holdRef, $amountMinor, "withdrawal_reserve");  
    }
}
