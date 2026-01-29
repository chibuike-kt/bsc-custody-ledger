<?php
declare(strict_types=1);

namespace App\App\Security;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class Jwt
{
  public static function issue(string $userId): string
  {
    $key = (string)getenv('APP_KEY');
    $iss = (string)getenv('APP_JWT_ISSUER');
    $ttl = (int)(getenv('APP_JWT_TTL_SECONDS') ?: 3600);
    $now = time();

    $payload = [
      'iss' => $iss,
      'sub' => $userId,
      'iat' => $now,
      'exp' => $now + $ttl,
    ];

    return JWT::encode($payload, $key, 'HS256');
  }

  public static function tryUserIdFromBearer(string $authorizationHeader): ?string
  {
    $authorizationHeader = trim($authorizationHeader);
    if (!str_starts_with(strtolower($authorizationHeader), 'bearer ')) return null;

    $token = trim(substr($authorizationHeader, 7));
    if ($token === '') return null;

    try {
      $decoded = JWT::decode($token, new Key((string)getenv('APP_KEY'), 'HS256'));
      $sub = $decoded->sub ?? null;
      return is_string($sub) && $sub !== '' ? $sub : null;
    } catch (\Throwable) {
      return null;
    }
  }
}
