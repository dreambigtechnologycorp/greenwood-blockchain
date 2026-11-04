<?php
declare(strict_types=1);
namespace Greenwood\Tests\Unit\Compute;

use Greenwood\Compute\Protocol\RequestState;
use Greenwood\Compute\Verification\ComputeRegistry;
use Greenwood\Compute\Verification\ComputeReceiptVerifier;
use Greenwood\Tests\Fixtures\ComputeFixture as F;
use PHPUnit\Framework\TestCase;

final class ComputeVerificationTest extends TestCase
{
    private function rejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected invalid compute transition');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function testLifecycleAndReadOnlyVerification(): void
    {
        $registry = new ComputeRegistry();
        $request = F::request();
        $id = $request->getId();
        $provider = F::providerId()->toBytes();
        $key = F::key()->getPublicKey();
        $receipt = F::receipt()->sign(F::key());
        $verifier = new ComputeReceiptVerifier($registry);
        $this->rejected(fn () => $verifier->accept($receipt, $key));
        $registry->registerRequest($request);
        self::assertSame(RequestState::PENDING, $registry->getState($id));
        $this->rejected(fn () => $registry->registerRequest($request));
        $this->rejected(fn () => $registry->acceptTerminalReceipt($receipt, $key));
        self::assertNull($registry->getAssignment($id));
        $registry->assign($id, $provider);
        $registry->assign($id, $provider);
        $this->rejected(fn () => $registry->assign($id, str_repeat('x', 32)));
        $verifier->verify($receipt, $key);
        self::assertSame(RequestState::ASSIGNED, $registry->getState($id));
        self::assertNull($registry->getTerminalReceiptId($id));
        $verifier->accept($receipt, $key);
        self::assertSame(RequestState::TERMINAL, $registry->getState($id));
        self::assertSame($receipt->getId(), $registry->getTerminalReceiptId($id));
        $this->rejected(fn () => $verifier->accept($receipt, $key));
        $conflict = F::receipt(['outputDigest' => str_repeat('x', 32)])->sign(F::key());
        $this->rejected(fn () => $verifier->accept($conflict, $key));
        $this->rejected(fn () => $registry->assign($id, $provider));
        self::assertSame($receipt->getId(), $registry->getTerminalReceiptId($id));
    }

    public function testRejectedReceiptsCannotMutateRegistryThroughEitherEntryPoint(): void
    {
        foreach ([
            ['requestId' => str_repeat('x', 32)],
            ['providerId' => str_repeat('x', 32)],
            ['workloadDigest' => str_repeat('x', 32)],
            ['inputDigest' => str_repeat('x', 32)],
        ] as $changes) {
            $registry = new ComputeRegistry();
            $request = F::request();
            $registry->registerRequest($request);
            $registry->assign($request->getId(), F::providerId()->toBytes());
            $receipt = F::receipt($changes)->sign(F::key());
            $this->rejected(fn () => (new ComputeReceiptVerifier($registry))->accept($receipt, F::key()->getPublicKey()));
            $this->rejected(fn () => $registry->acceptTerminalReceipt($receipt, F::key()->getPublicKey()));
            self::assertSame(RequestState::ASSIGNED, $registry->getState($request->getId()));
            self::assertNull($registry->getTerminalReceiptId($request->getId()));
        }
    }

    public function testSignatureFailureThenValidSettlement(): void
    {
        $registry = new ComputeRegistry();
        $request = F::request();
        $registry->registerRequest($request);
        $registry->assign($request->getId(), F::providerId()->toBytes());
        $receipt = F::receipt();
        $key = F::key()->getPublicKey();
        $this->rejected(fn () => $registry->acceptTerminalReceipt($receipt, $key));
        $receipt->setSignature(str_repeat("\0", 64));
        $this->rejected(fn () => $registry->acceptTerminalReceipt($receipt, $key));
        $receipt->sign(F::key());
        foreach (['', str_repeat('x', 32), $key . 'x'] as $badKey) {
            $this->rejected(fn () => $registry->acceptTerminalReceipt($receipt, $badKey));
        }
        self::assertSame(RequestState::ASSIGNED, $registry->getState($request->getId()));
        $registry->acceptTerminalReceipt($receipt, $key);
        self::assertSame($receipt->getId(), $registry->getTerminalReceiptId($request->getId()));
    }
}
