<?php
declare(strict_types=1);
namespace Greenwood\Tests\Unit\Compute;

use Greenwood\Compute\Codec\ComputeReceiptCodec;
use Greenwood\Compute\Protocol\ComputeStatus;
use Greenwood\Compute\Protocol\ProviderId;
use Greenwood\Compute\Runtime\ExecutionStatus;
use Greenwood\Crypto\Ed25519KeyPair;
use Greenwood\Tests\Fixtures\ComputeFixture;
use PHPUnit\Framework\TestCase;

final class ComputeReceiptTest extends TestCase
{
    public function testFrozenReceiptEncodingIdAndSignature(): void
    {
        $receipt = ComputeFixture::receipt();
        self::assertSame(ComputeFixture::RECEIPT_UNSIGNED, bin2hex($receipt->toUnsignedBytes()));
        self::assertSame(ComputeFixture::RECEIPT_ID, bin2hex($receipt->getId()));

        $receipt->sign(ComputeFixture::key());
        self::assertSame(ComputeFixture::RECEIPT_SIGNATURE, bin2hex($receipt->getSignature()));
        self::assertSame(66 + strlen($receipt->toUnsignedBytes()), strlen($receipt->toSignedBytes()));
    }

    public function testRoundTripSigned(): void
    {
        $receipt = ComputeFixture::receipt()->sign(ComputeFixture::key());
        $decoded = ComputeReceiptCodec::decode($receipt->toSignedBytes());
        self::assertSame($receipt->toSignedBytes(), $decoded->toSignedBytes());
        self::assertSame($receipt->getId(), $decoded->getId());
    }

    public function testSignatureVerification(): void
    {
        $receipt = ComputeFixture::receipt()->sign(ComputeFixture::key());
        self::assertTrue($receipt->verifySignature(hex2bin(ComputeFixture::PROVIDER_PUBLIC_KEY)));

        // Wrong key fails both binding and signature.
        $other = Ed25519KeyPair::generate();
        self::assertFalse($receipt->verifySignature($other->getPublicKey()));

        // Any mutation invalidates the original signature.
        $mutated = ComputeFixture::receipt(['outputDigest' => ComputeFixture::workloadDigest()]);
        $mutated->setSignature($receipt->getSignature());
        self::assertFalse($mutated->verifySignature(hex2bin(ComputeFixture::PROVIDER_PUBLIC_KEY)));
    }

    public function testProviderIdentityBinding(): void
    {
        $id = ComputeFixture::providerId();
        self::assertSame(ComputeFixture::PROVIDER_ID, (string) $id);
        self::assertTrue($id->matchesPublicKey(hex2bin(ComputeFixture::PROVIDER_PUBLIC_KEY)));
        self::assertFalse($id->matchesPublicKey(Ed25519KeyPair::generate()->getPublicKey()));
        $this->expectException(\InvalidArgumentException::class);
        ProviderId::fromPublicKey(str_repeat("\x00", 31));
    }

    public function testStatusEncodingFrozenAndMapsExecutionStatus(): void
    {
        self::assertSame([0, 1, 2, 3, 4], [
            ComputeStatus::fromExecutionStatus(ExecutionStatus::completed()),
            ComputeStatus::fromExecutionStatus(ExecutionStatus::failed()),
            ComputeStatus::fromExecutionStatus(ExecutionStatus::timedOut()),
            ComputeStatus::fromExecutionStatus(ExecutionStatus::terminated()),
            ComputeStatus::fromExecutionStatus(ExecutionStatus::runtimeError()),
        ]);
        foreach ([0, 1, 2, 3, 4] as $code) {
            $status = ComputeStatus::toExecutionStatus($code);
            self::assertSame($code, ComputeStatus::fromExecutionStatus($status));
        }
        $this->expectException(\InvalidArgumentException::class);
        ComputeStatus::toExecutionStatus(5);
    }

    public function testAllStatusesEncodeWithCorrectExitCodeRules(): void
    {
        // COMPLETED / FAILED: exit code required
        foreach ([0 => 0, 1 => 42] as $status => $code) {
            $r = ComputeFixture::receipt(['status' => $status, 'exitCode' => $code]);
            self::assertSame($r->toUnsignedBytes(), ComputeReceiptCodec::decodeUnsigned($r->toUnsignedBytes())->toUnsignedBytes());
            try {
                ComputeFixture::receipt(['status' => $status, 'exitCode' => null]);
                self::fail('Status '.$status.' must require exit code');
            } catch (\InvalidArgumentException) {}
        }
        // TIMED_OUT / TERMINATED: exit code optional
        foreach ([2, 3] as $status) {
            foreach ([137, null] as $code) {
                $r = ComputeFixture::receipt(['status' => $status, 'exitCode' => $code]);
                self::assertSame($r->toUnsignedBytes(), ComputeReceiptCodec::decodeUnsigned($r->toUnsignedBytes())->toUnsignedBytes());
            }
        }
        // RUNTIME_ERROR: exit code forbidden
        $r = ComputeFixture::receipt(['status' => 4, 'exitCode' => null]);
        self::assertSame($r->toUnsignedBytes(), ComputeReceiptCodec::decodeUnsigned($r->toUnsignedBytes())->toUnsignedBytes());
        $this->expectException(\InvalidArgumentException::class);
        ComputeFixture::receipt(['status' => 4, 'exitCode' => 255]);
    }

    public function testFuelUsedWithContractWorkload(): void
    {
        $r = ComputeFixture::receipt(['fuelUsed' => 16388]);
        self::assertSame(16388, ComputeReceiptCodec::decodeUnsigned($r->toUnsignedBytes())->getFuelUsed());
        self::assertNotSame(
            ComputeFixture::receipt()->getId(),
            ComputeFixture::receipt(['fuelUsed' => 16389])->getId()
        );
    }

    /**
     * Mutation resistance: each single-field change alters canonical bytes,
     * the receipt ID, and invalidates a signature made over the original.
     */
    public function testMutationChangesBytesAndId(): void
    {
        $base = ComputeFixture::receipt()->sign(ComputeFixture::key());
        $pub = hex2bin(ComputeFixture::PROVIDER_PUBLIC_KEY);
        $mutations = [
            ['requestId' => ComputeFixture::outputDigest()],
            ['providerId' => ProviderId::fromPublicKey(Ed25519KeyPair::generate()->getPublicKey())->toBytes()],
            ['executionId' => ComputeFixture::outputDigest()],
            ['workloadDigest' => ComputeFixture::inputDigest()],
            ['inputDigest' => ComputeFixture::workloadDigest()],
            ['outputDigest' => ComputeFixture::inputDigest()],
            ['outputRef' => 'ipfs://other'],
            ['status' => 1, 'exitCode' => 1],
            ['exitCode' => 1],
            ['cpuTimeMs' => 813],
            ['memoryPeakBytes' => 33554433],
            ['runtimeDurationMs' => 902],
            ['fuelUsed' => 1],
            ['outputBytesObserved' => 129],
            ['stdoutTruncated' => true],
            ['stderrTruncated' => false],
        ];
        foreach ($mutations as $overrides) {
            $mutated = ComputeFixture::receipt($overrides);
            self::assertNotSame($base->toUnsignedBytes(), $mutated->toUnsignedBytes(), 'bytes: '.implode(',', array_keys($overrides)));
            self::assertNotSame($base->getId(), $mutated->getId(), 'id: '.implode(',', array_keys($overrides)));
            $mutated->setSignature($base->getSignature());
            self::assertFalse($mutated->verifySignature($pub), 'sig must fail: '.implode(',', array_keys($overrides)));
        }
    }

    public function testRejectsUnknownVersionStatusFlagTruncationAndTrailing(): void
    {
        $bytes = hex2bin(ComputeFixture::RECEIPT_UNSIGNED);
        // version
        $this->assertInvalid(fn () => ComputeReceiptCodec::decodeUnsigned("\x00\x02" . substr($bytes, 2)));
        // status byte at offset 2 + 6*32 + 4 + 13 = 211
        $bad = $bytes; $bad[211] = "\x09";
        $this->assertInvalid(fn () => ComputeReceiptCodec::decodeUnsigned($bad));
        // exit_code_present at 212 must be 0 or 1
        $bad = $bytes; $bad[212] = "\x02";
        $this->assertInvalid(fn () => ComputeReceiptCodec::decodeUnsigned($bad));
        // trailing bytes
        $this->assertInvalid(fn () => ComputeReceiptCodec::decodeUnsigned($bytes . "\x00"));
        // truncation
        $this->assertInvalid(fn () => ComputeReceiptCodec::decodeUnsigned(substr($bytes, 0, 100)));
        // signed decode with wrong signature length
        $this->assertInvalid(fn () => ComputeReceiptCodec::decode($bytes . "\x00\x40" . str_repeat("\x00", 63)));
    }

    private function assertInvalid(callable $fn): void
    {
        try {
            $fn();
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function testUnsignedReceiptCannotProduceSignedBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComputeFixture::receipt()->toSignedBytes();
    }
}
