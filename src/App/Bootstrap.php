<?php
declare(strict_types=1);

namespace App\App;

use Dotenv\Dotenv;
use App\App\Http\Router;
use App\App\Http\Request;
use App\App\Http\JsonResponse;

final class Bootstrap
{
  public static function run(): void
  {
    $root = dirname(__DIR__, 2);
    if (file_exists($root . '/.env')) {
      Dotenv::createImmutable($root)->safeLoad();
    }

    $router = new Router();

    // health
    $router->get('/health', function () {
      return JsonResponse::ok(['ok' => true]);
    });

    // auth
    $router->post('/auth/register', [\App\Domain\Auth\AuthController::class, 'register']);
    $router->post('/auth/login',    [\App\Domain\Auth\AuthController::class, 'login']);

    // wallets
    $router->get('/wallets/deposit-address', [\App\Domain\Wallets\WalletController::class, 'getDepositAddress'], requireAuth: true);

    $req = Request::fromGlobals();
    $res = $router->dispatch($req);
    $res->send();
  }
}
