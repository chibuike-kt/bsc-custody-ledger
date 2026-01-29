<?php
declare(strict_types=1);

namespace App\Domain\Ledger;

use App\App\Http\JsonResponse;
use App\App\Http\Request;
use App\Infrastructure\Db\Connection;

final class LedgerController
{
  public function balance(Request $req): JsonResponse
  {
    $pdo = Connection::pdo();
    $currency = (string)($req->query['currency'] ?? 'USDT.BSC');

    $stmt = $pdo->prepare("SELECT balance_minor FROM accounts WHERE user_id=? AND type='spot' AND currency=? LIMIT 1");
    $stmt->execute([(string)$req->userId, $currency]);
    $row = $stmt->fetch();

    return JsonResponse::ok([
      'currency' => $currency,
      'balance_minor' => $row ? (string)$row['balance_minor'] : '0'
    ]);
  }
}
