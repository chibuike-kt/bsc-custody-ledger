<?php
declare(strict_types=1);

namespace App\Domain\Wallets;

use App\App\Http\Request;
use App\App\Http\JsonResponse;

final class WalletController
{
  public function getDepositAddress(Request $req): JsonResponse
  {
    $chain = (string)($req->query['chain'] ?? 'bsc');
    $svc = new WalletAddressService();
    $out = $svc->getOrCreateDepositAddress((string)$req->userId, $chain);
    return JsonResponse::ok($out);
  }
}
