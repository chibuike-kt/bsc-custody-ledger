<?php
declare(strict_types=1);

namespace App\Domain\Ledger;

use App\App\Http\JsonResponse;
use App\App\Http\Request;
use App\Infrastructure\Db\Connection;
use Brick\Math\BigInteger;

final class LedgerController
{
  public function balance(Request $req): JsonResponse
  {
    $pdo = Connection::pdo();
    $currency = (string)($req->query['currency'] ?? 'USDT.BSC');

    $stmt = $pdo->prepare("SELECT id, balance_minor FROM accounts WHERE user_id=? AND type='spot' AND currency=? LIMIT 1");
    $stmt->execute([(string)$req->userId, $currency]);
    $row = $stmt->fetch();

    if (!$row) {
      return JsonResponse::ok([
        'currency' => $currency,
        'balance_minor' => '0',
        'held_minor' => '0',
        'available_minor' => '0'
      ]);
    }

    $acctId = (string)$row['id'];
    $balance = BigInteger::of((string)$row['balance_minor']);

    $svc = new LedgerService();
    $held = BigInteger::of($svc->activeHoldsTotal($acctId));

    $available = $balance->minus($held);
    if ($available->isLessThan(BigInteger::zero())) $available = BigInteger::zero();

    return JsonResponse::ok([
      'currency' => $currency,
      'balance_minor' => $balance->toBase(10),
      'held_minor' => $held->toBase(10),
      'available_minor' => $available->toBase(10),
    ]);
  }
}
