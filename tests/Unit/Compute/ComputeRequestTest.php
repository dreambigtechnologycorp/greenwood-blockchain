<?php
declare(strict_types=1);
namespace Greenwood\Tests\Unit\Compute;

use Greenwood\Compute\Codec\ComputeRequestCodec;
use Greenwood\Compute\Protocol\ComputeProtocol;
use Greenwood\Compute\Protocol\TrustClass;
use Greenwood\Tests\Fixtures\ComputeFixture;
use PHPUnit\Framework\TestCase;

final class ComputeRequestTest extends TestCase
{
    public function testFrozenRequestEncodingAndId(): void
    {
        $request = ComputeFixture::request();
        self::assertSame(ComputeFixture::REQUEST_BYTES, bin2hex($request->toBytes()));
        self::assertSame(ComputeFixture::REQUEST_ID, bin2hex($request->getId()));
        self::assertSame(1, ComputeProtocol::VERSION);
    }

    public function testRoundTrip(): void
    {
        $request = ComputeFixture::request();
        $decoded = ComputeRequestCodec::decode($request->toBytes());
        self::assertSame($request->toBytes(), $decoded->toBytes());
        self::assertSame($request->getId(), $decoded->getId());
    }

    public function testExtremeValuesEncode(): void
    {
        $r = ComputeFixture::request([
            'requestNonce' => 0xFFFFFFFF,
            'trustClass' => TrustClass::SOVEREIGN,
            'cpuLimitMillis' => 0xFFFFFFFF,
            'memoryLimitBytes' => PHP_INT_MAX,
            'pidLimit' => 0xFFFFFFFF,
            'executionTimeoutMs' => PHP_INT_MAX,
            'outputRetentionBytes' => 0xFFFFFFFF,
            'maxPayment' => PHP_INT_MAX,
            'deadlineHeight' => PHP_INT_MAX,
            'workloadRef' => str_repeat('x', 256),
            'inputRef' => '',
        ]);
        self::assertSame($r->toBytes(), ComputeRequestCodec::decode($r->toBytes())->toBytes());

        $min = ComputeFixture::request([
            'requestNonce' => 0, 'trustClass' => TrustClass::OPEN,
            'cpuLimitMillis' => 0, 'memoryLimitBytes' => 0, 'pidLimit' => 0,
            'executionTimeoutMs' => 0, 'outputRetentionBytes' => 0,
            'maxPayment' => 0, 'deadlineHeight' => 0, 'workloadRef' => '',
        ]);
        self::assertSame($min->toBytes(), ComputeRequestCodec::decode($min->toBytes())->toBytes());
    }

    public function testTrustClassNumericEncodingFrozen(): void
    {
        self::assertSame(['OPEN' => 0, 'TRUSTED' => 1, 'SOVEREIGN' => 2],
            ['OPEN' => TrustClass::OPEN, 'TRUSTED' => TrustClass::TRUSTED, 'SOVEREIGN' => TrustClass::SOVEREIGN]);
        self::assertTrue(TrustClass::isValid(0));
        self::assertTrue(TrustClass::isValid(2));
        self::assertFalse(TrustClass::isValid(3));
        $this->expectException(\InvalidArgumentException::class);
        ComputeFixture::request(['trustClass' => 3]);
    }

    /**
     * Mutation resistance: every single-field change must change canonical
     * bytes and request ID.
     */
    public function testMutationChangesBytesAndId(): void
    {
        $base = ComputeFixture::request();
        $mutations = [
            ['requester' => ComputeFixture::outputDigest()],
            ['requestNonce' => 8],
            ['workloadDigest' => ComputeFixture::inputDigest()],
            ['workloadRef' => 'docker.io/org/image@sha256:abd'],
            ['inputDigest' => ComputeFixture::workloadDigest()],
            ['inputRef' => 'input'],
            ['trustClass' => 2],
            ['cpuLimitMillis' => 2001],
            ['memoryLimitBytes' => 268435457],
            ['pidLimit' => 65],
            ['executionTimeoutMs' => 30001],
            ['outputRetentionBytes' => 65537],
            ['maxPayment' => 1001],
            ['deadlineHeight' => 123457],
        ];
        foreach ($mutations as $overrides) {
            $mutated = ComputeFixture::request($overrides);
            self::assertNotSame($base->toBytes(), $mutated->toBytes(), 'bytes: '.json_encode($overrides));
            self::assertNotSame($base->getId(), $mutated->getId(), 'id: '.json_encode($overrides));
        }
    }

    public function testRejectsUnknownVersion(): void
    {
        $bytes = "\x00\x02" . substr(hex2bin(ComputeFixture::REQUEST_BYTES), 2);
        $this->expectException(\InvalidArgumentException::class);
        ComputeRequestCodec::decode($bytes);
    }

    public function testRejectsTrailingBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComputeRequestCodec::decode(hex2bin(ComputeFixture::REQUEST_BYTES) . "\x00");
    }

    public function testRejectsTruncation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComputeRequestCodec::decode(substr(hex2bin(ComputeFixture::REQUEST_BYTES), 0, 40));
    }

    public function testRejectsOversizedRef(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComputeFixture::request(['workloadRef' => str_repeat('x', 257)]);
    }

    public function testRejectsBadDigestLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComputeFixture::request(['workloadDigest' => str_repeat("\x00", 31)]);
    }

    public function testRejectsBadRequesterLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComputeFixture::request(['requester' => str_repeat("\x00", 33)]);
    }

    public function testRejectsOverflow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComputeFixture::request(['cpuLimitMillis' => 0x100000000]);
    }
}
