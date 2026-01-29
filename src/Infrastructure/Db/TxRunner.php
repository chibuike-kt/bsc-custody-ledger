<?php
declare(strict_types=1);

namespace App\Infrastructure\Db;

use PDO;

final class TxRunner
{
  public static function run(PDO $pdo, callable $fn, int $maxAttempts = 3): mixed
  {
    $attempt = 0;

    while (true) {
      $attempt++;

      try {
        $pdo->beginTransaction();
        $result = $fn();
        $pdo->commit();
        return $result;
      } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
          try { $pdo->rollBack(); } catch (\Throwable $ignore) {}
        }

        if ($attempt >= $maxAttempts || !self::isRetryable($e)) {
          throw $e;
        }

        $base = 50_000; // 50ms
        $sleep = (int)min(400_000, $base * (2 ** ($attempt - 1)));
        $jitter = random_int(0, 50_000);
        usleep($sleep + $jitter);
      }
    }
  }

  private static function isRetryable(\Throwable $e): bool
  {
    if ($e instanceof \PDOException) {
      $sqlState = (string)($e->errorInfo[0] ?? $e->getCode() ?? '');
      $driverCode = (int)($e->errorInfo[1] ?? 0);

      if ($sqlState === '40001') return true;
      if ($driverCode === 1213) return true;
      if ($driverCode === 1205) return true;
    }

    $msg = strtolower($e->getMessage());
    if (str_contains($msg, 'deadlock')) return true;
    if (str_contains($msg, 'lock wait timeout')) return true;

    return false;
  }
}
