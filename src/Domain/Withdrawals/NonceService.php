<?php
declare(strict_types=1);

namespace App\Domain\Withdrawals;

use App\Infrastructure\Db\Connection;
use App\Infrastructure\Db\TxRunner;
use App\Infrastructure\Rpc\JsonRpcClient;
use App\Infrastructure\Rpc\Evm;
use Ramsey\Uuid\Uuid;

final class NonceService
{
  public function nextNonce(string $chain, string $walletAddress): int
  {
    $pdo = Connection::pdo();
    $rpc = new JsonRpcClient((string)getenv('BSC_RPC_URL'));
    $wallet = Evm::normalizeAddress($walletAddress);

    return TxRunner::run($pdo, function () use ($pdo, $rpc, $chain, $wallet) {
      $stmt = $pdo->prepare("SELECT next_nonce FROM chain_nonces WHERE chain=? AND wallet_address=? LIMIT 1 FOR UPDATE");
      $stmt->execute([$chain, $wallet]);
      $row = $stmt->fetch();

      if (!$row) {
        // initialize from chain pending nonce
        $nonceHex = (string)$rpc->call('eth_getTransactionCount', [$wallet, 'pending']);
        $nonce = (int)\Brick\Math\BigInteger::fromBase(ltrim(strtolower($nonceHex), '0x'), 16)->toBase(10);

        $ins = $pdo->prepare("INSERT INTO chain_nonces (id, chain, wallet_address, next_nonce) VALUES (?, ?, ?, ?)");
        $ins->execute([Uuid::uuid4()->toString(), $chain, $wallet, $nonce + 1]);
        return $nonce;
      }

      $nonce = (int)$row['next_nonce'];
      $upd = $pdo->prepare("UPDATE chain_nonces SET next_nonce=? WHERE chain=? AND wallet_address=?");
      $upd->execute([$nonce + 1, $chain, $wallet]);

      return $nonce;
    }, 3);
  }
}
