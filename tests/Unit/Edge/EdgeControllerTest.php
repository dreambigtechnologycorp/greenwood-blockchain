<?php

declare(strict_types=1);
namespace Greenwood\Tests\Unit\Edge;

use Greenwood\Edge\EdgeController;
use Greenwood\Edge\EdgeRouteRegistry;
use PHPUnit\Framework\TestCase;

final class EdgeControllerTest extends TestCase
{
    private string $dir;
    private bool $authorized = true;
    private bool $healthy = true;
    private bool $proxyOk = true;
    private bool $publicOk = true;
    private int $port = 20000;
    private int $observationAge = 0;
    private array $applied = [];
    private array $lastRoutes = [];

    protected function setUp(): void { $this->dir = sys_get_temp_dir() . '/gw-edge-' . bin2hex(random_bytes(8)); }
    protected function tearDown(): void {
        foreach (glob($this->dir . '/*') ?: [] as $file) unlink($file);
        if (is_dir($this->dir)) rmdir($this->dir);
    }
    private function registry(): EdgeRouteRegistry { return new EdgeRouteRegistry($this->dir, 'greenwoodblockchain.com'); }
    private function controller(): EdgeController {
        return new EdgeController($this->registry(),
            fn($principal) => $principal === 'controller-1' && $this->authorized,
            fn($id, $service, $release) => [
                'application_id' => $id, 'service' => $service, 'release_id' => $release,
                'provider_id' => 'A', 'address' => '10.77.0.100', 'port' => $this->port,
                'image_digest' => 'sha256:' . str_repeat('a', 64), 'instance_id' => 'instance-' . $release,
                'ready' => $this->healthy, 'state' => 'RUNNING', 'observed_at' => time() - $this->observationAge,
            ],
            function ($routes) { $this->applied[] = $routes; if ($this->proxyOk) $this->lastRoutes = $routes; return $this->proxyOk; },
            fn() => $this->publicOk, ['A' => '10.77.0.100'], 20000, 20099);
    }
    public function testRestartReconcilesDurableRouteAndPublicReadiness(): void {
        $r = $this->controller()->publish('controller-1', 42, 'web', 1);
        self::assertSame('gw-42-web.greenwoodblockchain.com', $r['hostname']);
        self::assertSame('PUBLIC_READY', $r['state']);
        $this->controller()->reconcile();
        self::assertCount(2, $this->applied);
        self::assertSame('PUBLIC_READY', $this->registry()->find(42, 'web')['state']);
        self::assertSame(20000, $this->lastRoutes[0]['binding']['port']);
    }
    public function testUnauthorizedPrincipalCannotPublishOrWithdraw(): void {
        $this->controller()->publish('controller-1', 42, 'web', 1);
        foreach (['publish', 'withdraw'] as $operation) {
            try {
                $this->controller()->$operation('other-controller', 42, 'web', 1);
                self::fail('Authorization bypass');
            } catch (\RuntimeException $e) { self::assertStringContainsString('not authorized', $e->getMessage()); }
        }
        self::assertCount(1, $this->applied);
        self::assertSame('PUBLIC_READY', $this->registry()->find(42, 'web')['state']);
    }
    public function testUnhealthyReplacementLeavesCurrentRouteUntouched(): void {
        $this->controller()->publish('controller-1', 42, 'web', 1);
        $this->healthy = false;
        try { $this->controller()->publish('controller-1', 42, 'web', 2); self::fail('Published unhealthy backend'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('healthy', $e->getMessage()); }
        self::assertSame(1, $this->registry()->find(42, 'web')['release_id']);
        self::assertSame(1, $this->lastRoutes[0]['release_id']);
    }
    public function testHealthLossWithdrawsAndRecoveryReconciles(): void {
        $this->controller()->publish('controller-1', 42, 'web', 1);
        $this->healthy = false;
        $this->controller()->reconcile();
        self::assertSame([], $this->lastRoutes);
        self::assertSame('WITHDRAWN', $this->registry()->find(42, 'web')['state']);
        $this->healthy = true;
        $this->controller()->reconcile();
        self::assertSame('PUBLIC_READY', $this->registry()->find(42, 'web')['state']);
    }
    public function testStaleHealthCannotKeepRoutePublished(): void {
        $this->controller()->publish('controller-1', 42, 'web', 1);
        $this->observationAge = 31;
        $this->controller()->reconcile();
        self::assertSame([], $this->lastRoutes);
        self::assertSame('WITHDRAWN', $this->registry()->find(42, 'web')['state']);
    }
    public function testExplicitWithdrawalSurvivesRestart(): void {
        $this->controller()->publish('controller-1', 42, 'web', 1);
        $this->controller()->withdraw('controller-1', 42, 'web');
        $this->controller()->reconcile();
        self::assertSame([], $this->lastRoutes);
        self::assertSame('WITHDRAWN', $this->registry()->find(42, 'web')['desired']);
    }
    public function testExternalTransportFailureDoesNotEraseInternalPublication(): void {
        $this->publicOk = false;
        self::assertSame('PUBLISHED', $this->controller()->publish('controller-1', 42, 'web', 1)['state']);
        self::assertCount(1, $this->lastRoutes);
    }
    public function testProxyFailureNeverReportsPublicReadyAndCanRecover(): void {
        $this->controller()->publish('controller-1', 42, 'web', 1);
        $this->proxyOk = false;
        try { $this->controller()->reconcile(); self::fail('Proxy failure ignored'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('not confirmed', $e->getMessage()); }
        self::assertSame('HEALTHY', $this->registry()->find(42, 'web')['state']);
        $this->proxyOk = true;
        $this->controller()->reconcile();
        self::assertSame('PUBLIC_READY', $this->registry()->find(42, 'web')['state']);
    }
    public function testAdministrativePortsCannotBecomeApplicationBackends(): void {
        $this->port = 8787;
        $this->expectExceptionMessage('No authorized healthy');
        $this->controller()->publish('controller-1', 42, 'web', 1);
    }
    public function testHealthReporterCannotSilentlyMigrateBackend(): void {
        $this->controller()->publish('controller-1', 42, 'web', 1);
        $this->port = 20001;
        $this->controller()->reconcile();
        self::assertSame([], $this->lastRoutes);
        self::assertSame('WITHDRAWN', $this->registry()->find(42, 'web')['state']);
        $this->controller()->publish('controller-1', 42, 'web', 2);
        self::assertSame(20001, $this->lastRoutes[0]['binding']['port']);
    }
    public function testRevokedPublicationAuthorizationWithdrawsRoute(): void {
        $this->controller()->publish('controller-1', 42, 'web', 1);
        $this->authorized = false;
        $this->controller()->reconcile();
        self::assertSame([], $this->lastRoutes);
    }
    public function testHostnameCannotClaimNamedAdministrativeRoute(): void {
        $registry = $this->registry();
        self::assertNotSame($registry->hostname(1, 'web'), $registry->hostname(2, 'web'));
        $this->expectException(\InvalidArgumentException::class);
        $registry->hostname(1, 'provider-a.greenwoodblockchain.com');
    }
    public function testDomainPolicyCannotDriftOnRestart(): void {
        $this->registry();
        $this->expectExceptionMessage('requires migration');
        new EdgeRouteRegistry($this->dir, 'other.example.com');
    }
}
