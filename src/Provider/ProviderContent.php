<?php
declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\Crypto\ProtocolHash;

/** Frozen content/identity conventions. Does not fetch or execute workloads. */
final class ProviderContent
{
    public static function workloadDigest(string $ociDigest): string
    {
        if (!preg_match('/^sha256:([0-9a-f]{64})$/D', $ociDigest, $matches)) throw new \InvalidArgumentException('Immutable OCI SHA-256 digest required');
        return hex2bin($matches[1]);
    }

    public static function inputDigest(string $exactBytes): string { return hash('sha256', $exactBytes, true); }

    public static function executionId(string $runtimeId): string
    {
        if ($runtimeId === '' || preg_match('/[^\x00-\x7f]/', $runtimeId)) throw new \InvalidArgumentException('Runtime execution ID must be nonempty ASCII');
        return ProtocolHash::hash('GREENWOOD_EXECUTION_V1', $runtimeId);
    }

    public static function outputDigest(int $stdoutLength, string $stdoutHash, int $stderrLength, string $stderrHash): string
    {
        return \Greenwood\Compute\Runtime\OutputCommitment::digest($stdoutLength, $stdoutHash, $stderrLength, $stderrHash);
    }
}
