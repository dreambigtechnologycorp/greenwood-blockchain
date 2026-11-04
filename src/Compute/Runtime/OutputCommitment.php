<?php
declare(strict_types=1);
namespace Greenwood\Compute\Runtime;

use Greenwood\Codec\Canonical;

/** Full-stream commitments are independent of bounded retained output. */
final class OutputCommitment
{
    public static function digest(int $stdoutLength, string $stdoutHash, int $stderrLength, string $stderrHash): string
    {
        if (strlen($stdoutHash) !== 32 || strlen($stderrHash) !== 32) throw new \InvalidArgumentException('Raw SHA-256 stream hashes required');
        return hash('sha256', Canonical::encodeU64BE($stdoutLength) . $stdoutHash
            . Canonical::encodeU64BE($stderrLength) . $stderrHash, true);
    }

    public static function addBytes(int $observed, int $additional): int
    {
        if ($observed < 0 || $additional < 0 || $additional > PHP_INT_MAX - $observed) throw new \OverflowException('Output byte count exceeds platform integer range');
        return $observed + $additional;
    }
}
