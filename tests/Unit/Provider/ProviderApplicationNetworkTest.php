<?php

declare(strict_types=1);
namespace Greenwood\Tests\Unit\Provider;
use Greenwood\Provider\{ProviderApplicationBindingRegistry, ProviderApplicationNetwork};
use Greenwood\Compute\Containerd\OciImageRuntimeConfig;
use PHPUnit\Framework\TestCase;

final class ProviderApplicationNetworkTest extends TestCase
{
    private string $dir;
    private bool $healthy = true;
    private bool $cleanup = true;
    private int $pid = 123;
    private array $seen = [];
    protected function setUp(): void { $this->dir = sys_get_temp_dir() . '/gw-network-' . bin2hex(random_bytes(8)); }
    protected function tearDown(): void { foreach (glob($this->dir . '/*') ?: [] as $p) unlink($p); rmdir($this->dir); }
    private function network(): ProviderApplicationNetwork {
        $b = new ProviderApplicationBindingRegistry($this->dir, '10.254.16.0/28', 20000, 20003);
        return new ProviderApplicationNetwork($this->dir, $b,
            function ($row, $pid) { $this->seen[] = $row; return ['namespace_identity' => 'ns-' . $pid]; },
            fn() => $this->healthy, fn() => $this->cleanup);
    }
    private function reserve(ProviderApplicationNetwork $n): void { $n->reserve(1, 'isolated', new OciImageRuntimeConfig(['/app'], [], '/', ['8080/tcp'])); }
    private function runtime(): array { return ['application_id' => 1, 'service' => 'isolated', 'image_digest' => 'sha256:' . str_repeat('a', 64)]; }
    private function inspector(): \Closure { return fn() => ['runtime_state' => 'RUNNING', 'pid' => $this->pid]; }
    public function testOperatorReservationDoesNotPullStartOrAttach(): void {
        $n=$this->network();$registry=new \Greenwood\Provider\ProviderApplicationRegistry($this->dir);
        $forbidden=static function(){throw new \RuntimeException('Unexpected runtime side effect');};
        $inspector=new \Greenwood\Provider\ProviderApplicationRuntimeInspector($forbidden,$forbidden,$forbidden);
        $reconciler=new \Greenwood\Provider\ProviderApplicationReconciler($registry,$inspector);
        $allowed=true;
        $operator=new \Greenwood\Provider\ProviderApplicationOperator($registry,$reconciler,$forbidden,
            static fn()=>new OciImageRuntimeConfig(['/app'],[],'/',['8080/tcp']),$forbidden,
            network:$n,mutationGuard:static function()use(&$allowed){if(!$allowed)throw new \RuntimeException('Not permitted');});
        $image='registry/apps/1/web@sha256:'.str_repeat('a',64);
        $a=$operator->reserve(1,'web',$image);$b=$operator->reserve(1,'web',$image);
        self::assertSame($a,$b);self::assertTrue($a['reserved']);self::assertFalse($a['ready']);
        self::assertSame(20000,$a['ingress_port']);self::assertSame('RESERVED',$n->find(1,'web')['state']);
        self::assertSame([],$this->seen);self::assertNull($registry->find(1,'web'));
        $allowed=false;$this->expectExceptionMessage('Not permitted');$operator->reserve(2,'web',$image);
    }
    public function testRestartRetainsBindingAndReconcilesNewPid(): void {
        $n = $this->network(); $this->reserve($n);
        $a = $n->reconcile($this->runtime(), $this->inspector());
        $this->pid = 456; $n = $this->network();
        $b = $n->reconcile($this->runtime(), $this->inspector());
        self::assertSame($a['ingress_port'], $b['ingress_port']);
        self::assertSame($a['host_address'], $b['host_address']);
        self::assertSame('ns-456', $b['namespace_identity']);
        self::assertTrue($b['ready']); self::assertSame('HEALTHY', $b['state']);
    }
    public function testReplacementReservationRequiresFreshRetirementAndBindsDigest(): void {
        $n=$this->network();$registry=new \Greenwood\Provider\ProviderApplicationRegistry($this->dir);
        $old='registry/apps/1/web@sha256:'.str_repeat('a',64);
        $new='registry/apps/1/web@sha256:'.str_repeat('b',64);
        $registry->beginStart(1,'web','app-1-web',$old,'sha256:'.str_repeat('a',64));
        $registry->markFailed(1,'web');
        $view=['runtime_state'=>'ABSENT','container_present'=>false,'task_present'=>false,'snapshot_present'=>false,'lease_present'=>true];
        $forbidden=static fn()=>throw new \RuntimeException('Unexpected runtime side effect');
        $inspector=new \Greenwood\Provider\ProviderApplicationRuntimeInspector($forbidden,$forbidden,$forbidden);
        $operator=new \Greenwood\Provider\ProviderApplicationOperator($registry,
            new \Greenwood\Provider\ProviderApplicationReconciler($registry,$inspector),$forbidden,
            static fn()=>new OciImageRuntimeConfig(['/app'],[],'/',['8080/tcp']),$forbidden,
            inspectRuntime:static function()use(&$view){return $view;},network:$n);
        try {$operator->reserve(1,'web',$new);self::fail('Lease residue accepted');}
        catch (\RuntimeException $e) {self::assertSame('Previous runtime cleanup is unverified',$e->getMessage());}
        self::assertNull($n->find(1,'web'));
        $view['lease_present']=false;
        $first=$operator->reserve(1,'web',$new);
        self::assertSame($first,$operator->reserve(1,'web',$new));
        self::assertSame('sha256:'.str_repeat('b',64),$n->find(1,'web')['reservation_image_digest']);
        self::assertSame($old,$registry->get(1,'web')['image_ref']);
        try {$operator->reserve(1,'web','registry/apps/1/web@sha256:'.str_repeat('c',64));self::fail('Conflicting replacement accepted');}
        catch (\RuntimeException $e) {self::assertSame('Previous network is not retired or reservation conflicts',$e->getMessage());}
        $view['runtime_state']='RUNNING';
        $this->expectExceptionMessage('Previous runtime is not absent');$operator->reserve(1,'web',$new);
    }
    public function testFailedHealthIsNeverReady(): void {
        $n = $this->network(); $this->reserve($n); $this->healthy = false;
        try { $n->reconcile($this->runtime(), $this->inspector()); self::fail('Unhealthy accepted'); }
        catch (\RuntimeException $e) { self::assertSame('Network health not confirmed', $e->getMessage()); }
        self::assertFalse($n->find(1, 'isolated')['ready']);
        self::assertSame('RECONCILE_REQUIRED', $n->find(1, 'isolated')['state']);
    }
    public function testPidChangeDuringAttachmentFailsClosed(): void {
        $n = $this->network(); $this->reserve($n); $calls = 0;
        try { $n->reconcile($this->runtime(), function () use (&$calls) { return ['runtime_state' => 'RUNNING', 'pid' => ++$calls]; }); self::fail('PID race accepted'); }
        catch (\RuntimeException) { self::assertFalse($n->find(1, 'isolated')['ready']); }
    }
    public function testCleanupFailurePersistsAndCanBeRetried(): void {
        $n = $this->network(); $this->reserve($n); $n->reconcile($this->runtime(), $this->inspector());
        $this->cleanup = false;
        try { $n->cleanup(1, 'isolated'); self::fail('Cleanup failure accepted'); } catch (\RuntimeException) {}
        self::assertSame('CLEANUP_REQUIRED', $this->network()->find(1, 'isolated')['state']);
        self::assertFalse($n->observe(1, 'isolated')['ready']);
        $this->cleanup = true; self::assertSame('DETACHED', $n->cleanup(1, 'isolated')['state']);
        self::assertNotEmpty($n->find(1, 'isolated')['host_address']);
        $db = new \PDO('sqlite:' . $this->dir . '/network.sqlite');
        self::assertGreaterThan(0, (int)$db->query("SELECT COUNT(*) FROM network_events WHERE record LIKE '%CLEANUP_REQUIRED%'")->fetchColumn());
    }
    public function testPortChangeRequiresConfirmedDetachment(): void {
        $n = $this->network(); $this->reserve($n); $n->reconcile($this->runtime(), $this->inspector());
        $image = new OciImageRuntimeConfig(['/app'], [], '/', ['8081/tcp']);
        try { $n->reserve(1, 'isolated', $image); self::fail('Live port changed'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('reconciliation required', $e->getMessage()); }
        $n->cleanup(1, 'isolated'); $n->reserve(1, 'isolated', $image);
        self::assertSame(8081, $n->find(1, 'isolated')['application_port']);
        self::assertFalse($n->find(1, 'isolated')['ready']);
    }
    public function testFreshProbeOverridesPersistedHealthyEvidence(): void {
        $n = $this->network(); $this->reserve($n); $n->reconcile($this->runtime(), $this->inspector());
        $this->healthy = false; self::assertFalse($this->network()->observe(1, 'isolated')['ready']);
    }
    public function testReplacementAfterStopRetainsReservationAndNewIdentity(): void {
        $n = $this->network(); $this->reserve($n); $a = $n->reconcile($this->runtime(), $this->inspector());
        $n->cleanup(1, 'isolated'); $this->pid = 789;
        $r = $this->runtime(); $r['image_digest'] = 'sha256:' . str_repeat('b', 64);
        $b = $n->reconcile($r, $this->inspector());
        self::assertSame($a['ingress_port'], $b['ingress_port']); self::assertSame($r['image_digest'], $b['image_digest']);
        self::assertSame(789, $b['pid']);
    }
}
