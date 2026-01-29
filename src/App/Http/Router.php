<?php
declare(strict_types=1);

namespace App\App\Http;

use App\App\Security\Jwt;

final class Router
{
  /** @var array<string, array<string, array{handler:mixed, auth:bool}>> */
  private array $routes = [];

  public function get(string $path, mixed $handler, bool $requireAuth = false): void
  {
    $this->routes['GET'][$path] = ['handler' => $handler, 'auth' => $requireAuth];
  }

  public function post(string $path, mixed $handler, bool $requireAuth = false): void
  {
    $this->routes['POST'][$path] = ['handler' => $handler, 'auth' => $requireAuth];
  }

  public function dispatch(Request $req): JsonResponse
  {
    $def = $this->routes[$req->method][$req->path] ?? null;
    if (!$def) return JsonResponse::notFound();

    if ($def['auth']) {
      $auth = $req->headers['authorization'] ?? '';
      $userId = Jwt::tryUserIdFromBearer($auth);
      if (!$userId) return JsonResponse::unauthorized();
      $req->userId = $userId;
    }

    $h = $def['handler'];

    try {
      if (is_array($h) && is_string($h[0])) {
        $obj = new $h[0]();
        return $obj->{$h[1]}($req);
      }
      if (is_callable($h)) {
        return $h($req);
      }
      return JsonResponse::badRequest('invalid_handler');
    } catch (\DomainException $e) {
      return JsonResponse::badRequest($e->getMessage());
    } catch (\Throwable $e) {
      return new JsonResponse(500, ['error' => 'internal_error']);
    }
  }
}
