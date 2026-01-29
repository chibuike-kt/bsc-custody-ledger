<?php
declare(strict_types=1);

namespace App\Infrastructure\Rpc;

use Brick\Math\BigInteger;

final class Evm
{
  public static function normalizeAddress(string $a): string
  {
    $a = strtolower(trim($a));
    if (!str_starts_with($a, '0x')) throw new \DomainException('invalid_address');
    if (strlen($a) !== 42) throw new \DomainException('invalid_address');
    if (!ctype_xdigit(substr($a, 2))) throw new \DomainException('invalid_address');
    return $a;
  }

  public static function hexToDec(string $hex): string
  {
    $h = ltrim(strtolower($hex), '0x');
    if ($h === '') $h = '0';
    return BigInteger::fromBase($h, 16)->toBase(10);
  }

  public static function decToHex(string $dec): string
  {
    return '0x' . BigInteger::of($dec)->toBase(16);
  }
}
