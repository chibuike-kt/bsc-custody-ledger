<?php
declare(strict_types=1);

namespace App\Domain\Wallets;

/**
 * WARNING:
 * This is NOT production derivation.
 * It's a deterministic placeholder so you can build the rest of the rails (watchers, ledger, idempotency).
 *
 * Replace with:
 * - HD derivation inside a custody service, or
 * - MPC/HSM provider.
 */
final class DevAddressDeriver
{
  public static function deriveEvmAddressFromIndex(int $index): string
  {
    // Deterministic, not secret, not spendable.
    // We use it ONLY to build deposit monitoring pipelines.
    // For real funds, this must be replaced.
    $hex = substr(hash('sha256', 'dev-address:' . $index), 0, 40);
    return '0x' . $hex;
  }
}
