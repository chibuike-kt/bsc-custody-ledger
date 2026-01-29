<?php
declare(strict_types=1);

namespace App\Domain\Withdrawals;

use App\App\Http\Request;
use App\App\Http\JsonResponse;

final class WithdrawalController
{
  public function create(Request $req): JsonResponse
  {
    $idem = trim((string)($req->headers['idempotency-key'] ?? ''));
    if ($idem === '') throw new \DomainException('missing_idempotency_key');

    $chain = strtolower(trim((string)($req->json['chain'] ?? 'bsc')));
    $asset = strtoupper(trim((string)($req->json['asset'] ?? 'USDT')));
    $to = (string)($req->json['to_address'] ?? '');
    $amountMinor = (string)($req->json['amount_minor'] ?? '');

    $svc = new WithdrawalService();
    $out = $svc->create((string)$req->userId, $idem, $chain, $asset, $to, $amountMinor);

    return JsonResponse::created($out);
  }
}
