<?php
declare(strict_types=1);
namespace Greenwood\Compute\Runtime;
final class Monotonic
{
    public static function elapsedMs(int $start, int $end): int
    {
        if ($start < 0 || $end < $start) throw new \OverflowException('Invalid monotonic interval');
        return intdiv($end - $start, 1000000);
    }
}
