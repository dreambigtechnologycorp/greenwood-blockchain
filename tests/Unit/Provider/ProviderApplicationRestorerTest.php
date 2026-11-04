<?php
declare(strict_types=1);
namespace Greenwood\Tests\Unit\Provider;

use Greenwood\Provider\{ProviderApplicationRegistry, ProviderApplicationBindingRegistry, ProviderApplicationNetwork, ProviderApplicationRestorer};
use Greenwood\Compute\Containerd\OciImageRuntimeConfig;
use PHPUnit\Framework\TestCase;

final class ProviderApplicationRestorerTest extends TestCase
{
    private string $dir;
    private ProviderApplicationRegistry $registry;
    private ProviderApplicationNetwork $network;
    private array $view;
    private int $starts = 0;
    private bool $healthy = true;
    private bool $timeout = false;
    private bool $denied = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gw-restore-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
        $this->registry = new ProviderApplicationRegistry($this->dir);
        $this->network = new ProviderApplicationNetwork($this->dir,
            new ProviderApplicationBindingRegistry($this->dir, '10.254.32.0/24', 21001, 21100),
            static fn(array $b, int $pid): array => ['namespace_identity' => 'boot:'.$pid],
            fn(): bool => $this->healthy, static fn(): bool => true);
        $digest = 'sha256:' . str_repeat('a', 64);
        $this->registry->beginStart(1, 'web', 'app-1-web', 'registry/app@'.$digest, $digest);
        $this->registry->markRunning(1, 'web', 'app-1-web', 'gw-system-view-app-1-web', 'parent', 'gw-system-lease-app-1-web', 100);
        $this->network->reserve(1, 'web', new OciImageRuntimeConfig(['/app'], [], '/', ['3000/tcp']), $digest);
        $this->view = ['runtime_state'=>'RUNNING', 'pid'=>100, 'task_state'=>'RUNNING', 'container_present'=>true,
            'snapshot_present'=>true, 'lease_present'=>true, 'task_present'=>true, 'parent_snapshot'=>'parent'];
        $this->network->reconcile($this->registry->get(1, 'web'), fn() => $this->view);
        $this->view['runtime_state'] = 'RESIDUE'; $this->view['task_state'] = null;
        $this->view['task_present'] = false; $this->view['pid'] = null;
    }

    protected function tearDown(): void
    {
        unset($this->registry, $this->network);
        gc_collect_cycles();
        foreach (glob($this->dir.'/*') ?: [] as $file) unlink($file);
        rmdir($this->dir);
    }

    private function restorer(): ProviderApplicationRestorer
    {
        return new ProviderApplicationRestorer($this->registry, fn() => $this->view,
            function (): void {
                $this->starts++;
                $this->view['runtime_state']='RUNNING'; $this->view['pid']=200;
                $this->view['task_state']='RUNNING'; $this->view['task_present']=true;
                if ($this->timeout) throw new \RuntimeException('Start response lost');
            }, $this->network, function (): void { if ($this->denied) throw new \RuntimeException('Protected service'); });
    }

    public function testRestoresTaskAndBindingAndDoesNotDuplicateOnNextPass(): void
    {
        $before = $this->network->find(1, 'web');
        self::assertSame('HEALTHY', $this->restorer()->tick()[0]['outcome']);
        self::assertSame(200, (int)$this->registry->get(1, 'web')['pid']);
        self::assertSame('boot:200', $this->network->find(1, 'web')['namespace_identity']);
        self::assertSame($before['ingress_port'], $this->network->find(1, 'web')['ingress_port']);
        self::assertSame('HEALTHY', $this->restorer()->tick()[0]['outcome']);
        self::assertSame(1, $this->starts);
    }

    public function testLostStartResponseIsInspectedBeforeRetry(): void
    {
        $this->timeout = true;
        self::assertSame('RETRY_REQUIRED', $this->restorer()->tick()[0]['outcome']);
        self::assertFalse($this->network->find(1, 'web')['ready']);
        self::assertSame('HEALTHY', $this->restorer()->tick()[0]['outcome']);
        self::assertSame(1, $this->starts);
    }

    public function testSlowApplicationHealthRetriesNetworkWithoutRestartingTask(): void
    {
        $this->healthy = false;
        self::assertSame('RETRY_REQUIRED', $this->restorer()->tick()[0]['outcome']);
        self::assertFalse($this->network->find(1, 'web')['ready']);
        $this->healthy = true;
        self::assertSame('HEALTHY', $this->restorer()->tick()[0]['outcome']);
        self::assertSame(1, $this->starts);
    }

    public function testMissingLeaseAndWrongParentFailClosed(): void
    {
        $this->view['lease_present'] = false;
        self::assertSame('RETRY_REQUIRED', $this->restorer()->tick()[0]['outcome']);
        $this->view['lease_present'] = true; $this->view['parent_snapshot'] = 'other';
        self::assertSame('RETRY_REQUIRED', $this->restorer()->tick()[0]['outcome']);
        self::assertSame(0, $this->starts);
        self::assertFalse($this->network->find(1, 'web')['ready']);
    }

    public function testUnknownTaskStateAndProtectedServiceAreNotStarted(): void
    {
        $this->view['task_present'] = true; $this->view['task_state'] = 'OTHER';
        self::assertSame('RETRY_REQUIRED', $this->restorer()->tick()[0]['outcome']);
        $this->denied = true;
        self::assertSame('RETRY_REQUIRED', $this->restorer()->tick()[0]['outcome']);
        self::assertSame(0, $this->starts);
    }

    public function testStopIntentAndUncertainInitialStartAreNeverReplayed(): void
    {
        $this->registry->markStopping(1, 'web');
        self::assertSame([], $this->restorer()->tick());
        $this->registry->markStopped(1, 'web');
        self::assertSame([], $this->restorer()->tick());
        $digest = 'sha256:'.str_repeat('a',64);
        $this->registry->beginStart(2, 'web', 'app-2-web', 'registry/app@'.$digest, $digest);
        self::assertSame([], $this->restorer()->tick());
        self::assertSame(0, $this->starts);
    }

    public function testBusyApplicationLockPreventsConcurrentStart(): void
    {
        $result = $this->registry->exclusive(1, 'web', fn() => $this->restorer()->tick());
        self::assertSame('RETRY_REQUIRED', $result[0]['outcome']);
        self::assertSame(0, $this->starts);
    }
}
