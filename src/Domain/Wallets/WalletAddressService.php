<?php
declare(strict_types=1);

namespace App\Domain\Wallets;

use App\Infrastructure\Db\Connection;
use Ramsey\Uuid\Uuid;

/**
 * Dev-mode only: we allocate an address per user+chain and store a derivation_index.
 * In real custody, address derivation should be done in a dedicated custody/signing component.
 */
final class WalletAddressService
{
  public function getOrCreateDepositAddress(string $userId, string $chain): array
  {
    $chain = strtolower(trim($chain));
    if ($chain !== 'bsc') throw new \DomainException('unsupported_chain');

    $pdo = Connection::pdo();

    $stmt = $pdo->prepare("SELECT id, address, derivation_index FROM wallet_addresses WHERE user_id = ? AND chain = ? LIMIT 1");
    $stmt->execute([$userId, $chain]);
    $row = $stmt->fetch();
    if ($row) {
      return [
        'chain' => $chain,
        'address' => (string)$row['address'],
        'derivation_index' => (int)$row['derivation_index'],
      ];
    }

    // Allocate next index globally per chain
    $pdo->beginTransaction();
    try {
      $stmt = $pdo->prepare("SELECT COALESCE(MAX(derivation_index), 0) AS m FROM wallet_addresses WHERE chain = ? FOR UPDATE");
      $stmt->execute([$chain]);
      $m = (int)($stmt->fetch()['m'] ?? 0);
      $next = $m + 1;

      // DEV address derivation placeholder:
      // We do NOT derive from mnemonic here. We just require you to plug a dev key later for withdrawals.
      // For deposits learning, you can also allocate addresses externally and insert them, but we keep it automated.
      $address = DevAddressDeriver::deriveEvmAddressFromIndex($next);

      $id = Uuid::uuid4()->toString();
      $stmt = $pdo->prepare("INSERT INTO wallet_addresses (id, user_id, chain, address, derivation_index) VALUES (?, ?, ?, ?, ?)");
      $stmt->execute([$id, $userId, $chain, $address, $next]);

      $pdo->commit();

      return [
        'chain' => $chain,
        'address' => $address,
        'derivation_index' => $next,
      ];
    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}
