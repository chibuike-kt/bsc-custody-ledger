<?php
declare(strict_types=1);

namespace App\Domain\Withdrawals;

use App\Domain\Ledger\LedgerService;
use App\Infrastructure\Db\Connection;
use App\Infrastructure\Db\TxRunner;
use App\Infrastructure\Rpc\Evm;
use Brick\Math\BigInteger;
use Ramsey\Uuid\Uuid;

final class WithdrawalService
{
  public function create(string $userId, string $idempotencyKey, string $chain, string $asset, string $toAddress, string $amountMinor): array
  {
    if ($chain !== 'bsc') throw new \DomainException('unsupported_chain');
    if ($asset !== 'USDT') throw new \DomainException('unsupported_asset');

    $to = Evm::normalizeAddress($toAddress);

    $amt = BigInteger::of(trim($amountMinor));
    if ($amt->isLessThanOrEqualTo(BigInteger::zero())) throw new \DomainException('invalid_amount');

    $currency = 'USDT.BSC';

    $pdo = Connection::pdo();
    $ledger = new LedgerService();

    // request hash ensures same idem key must match same payload
    $requestHash = hash('sha256', implode('|', [$userId, $chain, $asset, $to, $amt->toBase(10)]));

    return TxRunner::run($pdo, function () use ($pdo, $ledger, $userId, $idempotencyKey, $chain, $asset, $to, $currency, $amt, $requestHash) {
      // idempotency: return exact same response
      $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? AND idempotency_key=? LIMIT 1 FOR UPDATE");
      $stmt->execute([$userId, $idempotencyKey]);
      $existing = $stmt->fetch();

      if ($existing) {
        if ((string)$existing['request_hash'] !== $requestHash) throw new \DomainException('idempotency_key_reuse_mismatch');
        return $this->shapeResponse($existing);
      }

      // ensure user spot account exists
      $userAcctId = $ledger->getOrCreateAccount($userId, 'spot', $currency);

      $withdrawalId = Uuid::uuid4()->toString();
      $holdRef = 'withdrawal:' . $withdrawalId;

      // reserve funds by hold (available balance check)
      $ledger->reserveForWithdrawal($userAcctId, $holdRef, $amt->toBase(10));

      // create withdrawal record
      $stmt = $pdo->prepare("
        INSERT INTO withdrawals
          (id, user_id, chain, asset, currency, to_address, amount_minor, status, idempotency_key, request_hash, hold_reference)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, 'created', ?, ?, ?)
      ");
      $stmt->execute([
        $withdrawalId,
        $userId,
        $chain,
        $asset,
        $currency,
        $to,
        $amt->toBase(10),
        $idempotencyKey,
        $requestHash,
        $holdRef,
      ]);

      $row = [
        'id' => $withdrawalId,
        'user_id' => $userId,
        'chain' => $chain,
        'asset' => $asset,
        'currency' => $currency,
        'to_address' => $to,
        'amount_minor' => $amt->toBase(10),
        'fee_minor' => '0',
        'status' => 'created',
        'idempotency_key' => $idempotencyKey,
        'request_hash' => $requestHash,
        'hold_reference' => $holdRef,
        'tx_hash' => null,
        'nonce' => null,
        'error_code' => null,
      ];

      return $this->shapeResponse($row);
    }, 3);
  }

  private function shapeResponse(array $row): array
  {
    return [
      'withdrawal_id' => (string)$row['id'],
      'chain' => (string)$row['chain'],
      'asset' => (string)$row['asset'],
      'currency' => (string)$row['currency'],
      'to_address' => (string)$row['to_address'],
      'amount_minor' => (string)$row['amount_minor'],
      'fee_minor' => (string)($row['fee_minor'] ?? '0'),
      'status' => (string)$row['status'],
      'tx_hash' => $row['tx_hash'] ? (string)$row['tx_hash'] : null,
      'nonce' => $row['nonce'] !== null ? (int)$row['nonce'] : null,
      'idempotency_key' => (string)$row['idempotency_key'],
    ];
  }
}
