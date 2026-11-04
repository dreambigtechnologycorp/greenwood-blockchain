<?php
declare(strict_types=1);
namespace Greenwood\Tests\Fixtures;

use Greenwood\Compute\Protocol\ComputeReceipt;
use Greenwood\Compute\Protocol\ComputeRequest;
use Greenwood\Compute\Protocol\ProviderId;
use Greenwood\Crypto\Ed25519KeyPair;
use Greenwood\Crypto\ProtocolHash;

/**
 * Frozen GREENWOOD_COMPUTE_V1 vectors (Pass 4B). Byte-for-byte frozen;
 * any change here is a protocol change.
 */
final class ComputeFixture
{
    public const PROVIDER_PUBLIC_KEY = '3fa7f56f64aab954f3acbc10671d5404c8f5ae0eff78d096b8d4cb614d5ed9f3';
    // Public test-vector signing material. Never use this identity on a network.
    public const PROVIDER_PRIVATE_KEY = '593867a76a74cdf0a361fd9c3dbea9a2f1672b7ab1a7ae8da1be1cbcb02054f33fa7f56f64aab954f3acbc10671d5404c8f5ae0eff78d096b8d4cb614d5ed9f3';
    public const PROVIDER_ID = 'd8a9717addb0d98585b540f70241fb25ed476f3242aa46a9cd146991694354b7';

    public const REQUEST_BYTES = '000137e634fd22445dcd9501fac21506297493de895af4255e1a2b0839b0ff2ade2a000000079427a747b89e695dd5349507d3c1041177ec034ddf86ae68cc6c46e4d454513f0000001e646f636b65722e696f2f6f72672f696d616765407368613235363a61626309bc247b0f437134e4668847f915c9ba9a89caeafe8265931a4ad90baadea21f0000000001000007d000000000100000000000004000000000000075300001000000000000000003e8000000000001e240';
    public const REQUEST_ID = 'c4b9850f9d60e4d789d2366e38bfe0866a1c2a3810048de60f02d67e6fde632f';

    public const RECEIPT_UNSIGNED = '0001c4b9850f9d60e4d789d2366e38bfe0866a1c2a3810048de60f02d67e6fde632fd8a9717addb0d98585b540f70241fb25ed476f3242aa46a9cd146991694354b7e57f7cd943bd3244868716471b07c1d116b7d1256be166d337cf2716f4ff03809427a747b89e695dd5349507d3c1041177ec034ddf86ae68cc6c46e4d454513f09bc247b0f437134e4668847f915c9ba9a89caeafe8265931a4ad90baadea21fc2f7ff1ce4002140dfe24de4ab772a3ab7c63c0f31c643e4ada40bc304883f620000000d697066733a2f2f6f7574707574000100000000000000000000032c000000000200000000000000000003850000000000000000800001';
    public const RECEIPT_ID = '458187a46ceb7b052cf87015157d087eaea955d7a6492763a95f010062ed25c6';
    public const RECEIPT_SIGNATURE = 'af20a3c69b91438ed84efc38c9084ef05774c67638649913b5813f89a6e16cde6d73c000bd7979d4cf75091a346c9a15d154bcd2680df850b81e314d2c84640f';

    public static function key(): Ed25519KeyPair
    {
        return new Ed25519KeyPair(
            hex2bin(self::PROVIDER_PUBLIC_KEY),
            hex2bin(self::PROVIDER_PRIVATE_KEY)
        );
    }

    public static function providerId(): ProviderId
    {
        return ProviderId::fromPublicKey(hex2bin(self::PROVIDER_PUBLIC_KEY));
    }

    public static function requester(): string
    {
        return ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', hex2bin(self::PROVIDER_PUBLIC_KEY));
    }

    public static function workloadDigest(): string { return ProtocolHash::hashValue('frozen-workload'); }
    public static function inputDigest(): string { return ProtocolHash::hashValue('frozen-input'); }
    public static function outputDigest(): string { return ProtocolHash::hashValue('frozen-output'); }
    public static function executionId(): string { return ProtocolHash::hashValue('frozen-execution'); }

    public static function request(array $overrides = []): ComputeRequest
    {
        return new ComputeRequest(
            requester: $overrides['requester'] ?? self::requester(),
            requestNonce: $overrides['requestNonce'] ?? 7,
            workloadDigest: $overrides['workloadDigest'] ?? self::workloadDigest(),
            workloadRef: $overrides['workloadRef'] ?? 'docker.io/org/image@sha256:abc',
            inputDigest: $overrides['inputDigest'] ?? self::inputDigest(),
            inputRef: $overrides['inputRef'] ?? '',
            trustClass: $overrides['trustClass'] ?? 1,
            cpuLimitMillis: $overrides['cpuLimitMillis'] ?? 2000,
            memoryLimitBytes: $overrides['memoryLimitBytes'] ?? 268435456,
            pidLimit: $overrides['pidLimit'] ?? 64,
            executionTimeoutMs: $overrides['executionTimeoutMs'] ?? 30000,
            outputRetentionBytes: $overrides['outputRetentionBytes'] ?? 65536,
            maxPayment: $overrides['maxPayment'] ?? 1000,
            deadlineHeight: $overrides['deadlineHeight'] ?? 123456,
        );
    }

    public static function receipt(array $overrides = []): ComputeReceipt
    {
        return new ComputeReceipt(
            requestId: $overrides['requestId'] ?? hex2bin(self::REQUEST_ID),
            providerId: $overrides['providerId'] ?? self::providerId()->toBytes(),
            executionId: $overrides['executionId'] ?? self::executionId(),
            workloadDigest: $overrides['workloadDigest'] ?? self::workloadDigest(),
            inputDigest: $overrides['inputDigest'] ?? self::inputDigest(),
            outputDigest: $overrides['outputDigest'] ?? self::outputDigest(),
            outputRef: $overrides['outputRef'] ?? 'ipfs://output',
            status: $overrides['status'] ?? 0,
            exitCode: \array_key_exists('exitCode', $overrides) ? $overrides['exitCode'] : 0,
            cpuTimeMs: $overrides['cpuTimeMs'] ?? 812,
            memoryPeakBytes: $overrides['memoryPeakBytes'] ?? 33554432,
            runtimeDurationMs: $overrides['runtimeDurationMs'] ?? 901,
            fuelUsed: \array_key_exists('fuelUsed', $overrides) ? $overrides['fuelUsed'] : null,
            outputBytesObserved: $overrides['outputBytesObserved'] ?? 128,
            stdoutTruncated: $overrides['stdoutTruncated'] ?? false,
            stderrTruncated: $overrides['stderrTruncated'] ?? true,
        );
    }
}
