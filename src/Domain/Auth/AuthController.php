<?php
declare(strict_types=1);

namespace App\Domain\Auth;

use App\App\Http\Request;
use App\App\Http\JsonResponse;
use App\App\Security\Jwt;
use App\Infrastructure\Db\Connection;
use Ramsey\Uuid\Uuid;

final class AuthController
{
  public function register(Request $req): JsonResponse
  {
    $email = strtolower(trim((string)($req->json['email'] ?? '')));
    $password = (string)($req->json['password'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new \DomainException('invalid_email');
    }
    if (strlen($password) < 10) {
      throw new \DomainException('weak_password');
    }

    $pdo = Connection::pdo();

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetch()) throw new \DomainException('email_taken');

    $userId = Uuid::uuid4()->toString();
    $hash = password_hash($password, PASSWORD_ARGON2ID);

    $stmt = $pdo->prepare("INSERT INTO users (id, email, password_hash) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $email, $hash]);

    $token = Jwt::issue($userId);

    return JsonResponse::created([
      'user_id' => $userId,
      'token' => $token
    ]);
  }

  public function login(Request $req): JsonResponse
  {
    $email = strtolower(trim((string)($req->json['email'] ?? '')));
    $password = (string)($req->json['password'] ?? '');

    if ($email === '' || $password === '') throw new \DomainException('invalid_credentials');

    $pdo = Connection::pdo();

    $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u) throw new \DomainException('invalid_credentials');

    if (!password_verify($password, (string)$u['password_hash'])) {
      throw new \DomainException('invalid_credentials');
    }

    $token = Jwt::issue((string)$u['id']);

    return JsonResponse::ok([
      'user_id' => (string)$u['id'],
      'token' => $token
    ]);
  }
}
