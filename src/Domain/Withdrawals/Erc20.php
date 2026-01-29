<?php
declare(strict_types=1);

namespace App\Domain\Withdrawals;

use App\Infrastructure\Rpc\Evm;
use Brick\Math\BigInteger;

final class Erc20
{
  // function transfer(address to, uint256 value) -> a9059cbb
  public static function encodeTransfer(string $toAddress, string $amountDec): string
  {
    $to = Evm::normalizeAddress($toAddress);
    $amt = BigInteger::of($amountDec);

    $method = 'a9059cbb';
    $toPadded = str_pad(substr($to, 2), 64, '0', STR_PAD_LEFT);
    $amtHex = $amt->toBase(16);
    $amtPadded = str_pad($amtHex, 64, '0', STR_PAD_LEFT);

    return '0x' . $method . $toPadded . $amtPadded;
  }
}
