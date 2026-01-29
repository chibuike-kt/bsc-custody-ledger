<?php
declare(strict_types=1);

namespace App\Infrastructure\Db;

use PDO;

final class Connection
{
  private static ?PDO $pdo = null;

  public static function pdo(): PDO
  {
    if (self::$pdo) return self::$pdo;

    $dsn = (string)getenv('DB_DSN');
    $user = (string)getenv('DB_USER');
    $pass = (string)getenv('DB_PASS');

    $pdo = new PDO($dsn, $user, $pass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    self::$pdo = $pdo;
    return $pdo;
  }
}
