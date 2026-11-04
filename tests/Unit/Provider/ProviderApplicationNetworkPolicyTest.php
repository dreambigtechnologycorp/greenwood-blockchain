<?php
declare(strict_types=1);
namespace Greenwood\Tests\Unit\Provider;
use Greenwood\Provider\{ProviderApplicationNetworkPolicy,ProviderApplicationRegistry,ProviderApplicationReconciler,ProviderApplicationRuntimeInspector,ProviderApplicationOperator};
use Greenwood\Compute\Containerd\RegistryTransferAuth;
use PHPUnit\Framework\TestCase;

final class ProviderApplicationNetworkPolicyTest extends TestCase
{
    private string $dir;
    protected function setUp(): void { $this->dir = sys_get_temp_dir().'/gw-network-policy-'.bin2hex(random_bytes(8)); mkdir($this->dir,0700); }
    protected function tearDown(): void { foreach(glob($this->dir.'/*')?:[]as$p)unlink($p);rmdir($this->dir); }
    private function values(): array { return ['providerAddress'=>'10.77.0.100','edgeAddress'=>'10.77.0.103','subnet'=>'10.254.32.0/24','firstPort'=>21000,'lastPort'=>21063,'forwardTable'=>'greenwood_app_ingress','forwardChain'=>'forward','protectedServices'=>[['application_id'=>1,'service'=>'web']]]; }
    public function testInvalidPoliciesFailBeforeStateCreation(): void
    {
        foreach ([['providerAddress'=>'8.8.8.8'],['subnet'=>'10.77.0.0/24'],['subnet'=>'10.254.32.1/24'],['subnet'=>'10.254.32.0/29'],['firstPort'=>80],['lastPort'=>70000],['forwardTable'=>'bad; command'],['edgeAddress'=>'10.77.0.100'],['protectedServices'=>[['application_id'=>1,'service'=>'web','command'=>'sh']]]] as $change) {
            try { new ProviderApplicationNetworkPolicy(...array_replace($this->values(),$change));self::fail('Invalid platform policy accepted'); }
            catch (\InvalidArgumentException) { self::assertSame([],glob($this->dir.'/*')); }
        }
    }
    public function testProtectedIdentityDoesNotProtectOtherApplications(): void
    {
        $p=new ProviderApplicationNetworkPolicy(...$this->values());
        $p->assertMutable(2,'web');$p->assertMutable(1,'new-web');
        $this->expectExceptionMessage('explicit migration required');$p->assertMutable(1,'web');
    }
    public function testPolicyFactoryCreatesOnlyUnattachedPersistentState(): void
    {
        $p=new ProviderApplicationNetworkPolicy(...$this->values());$network=$p->createNetwork($this->dir);
        self::assertNull($network->find(1,'new-web'));
        self::assertFileExists($this->dir.'/bindings.sqlite');self::assertFileExists($this->dir.'/network.sqlite');
        self::assertNull($p->createNetwork($this->dir)->find(1,'new-web'));
    }
    public function testChangedPoolRequiresMigration(): void
    {
        (new ProviderApplicationNetworkPolicy(...$this->values()))->createNetwork($this->dir);
        $this->expectExceptionMessage('explicit migration required');
        (new ProviderApplicationNetworkPolicy(...array_replace($this->values(),['firstPort'=>22000,'lastPort'=>22063])))->createNetwork($this->dir);
    }
    public function testUnclassifiedLegacyRuntimePreventsEnablement(): void
    {
        $p = new ProviderApplicationNetworkPolicy(...$this->values());
        $network = $p->createNetwork($this->dir);
        $p->assertCoverage([['application_id'=>1,'service'=>'web']], $network);
        self::assertNull($network->find(1,'web'));
        $this->expectExceptionMessage('no managed binding or explicit legacy protection');
        $p->assertCoverage([['application_id'=>2,'service'=>'web']], $network);
    }
    public function testEdgePolicyCannotSilentlyChangeExistingBindings(): void
    {
        (new ProviderApplicationNetworkPolicy(...$this->values()))->createNetwork($this->dir);
        $this->expectExceptionMessage('Platform network policy changed');
        (new ProviderApplicationNetworkPolicy(...array_replace($this->values(), ['edgeAddress'=>'10.77.0.102'])))->createNetwork($this->dir);
    }
    public function testRelativeAndSymlinkPolicyPathsAreRejected(): void
    {
        file_put_contents($this->dir.'/policy.json',json_encode($this->values()));symlink($this->dir.'/policy.json',$this->dir.'/link.json');
        foreach(['relative.json',$this->dir.'/link.json']as$path){
            try{ProviderApplicationNetworkPolicy::fromFile($path);self::fail('Unsafe path accepted');}
            catch(\InvalidArgumentException){self::assertTrue(true);}
        }
    }
    public function testLegacyRuntimeCannotBeStartedOrStoppedButInspectionWorks(): void
    {
        $registry=new ProviderApplicationRegistry($this->dir);$image='registry/apps/1/web@sha256:'.str_repeat('a',64);
        $registry->beginStart(1,'web','app-1-protected',$image,'sha256:'.str_repeat('a',64));
        $before=$registry->get(1,'web');
        $never=static fn()=>self::fail('Touched protected runtime');
        $inspector=new ProviderApplicationRuntimeInspector($never,$never,$never);
        $policy=new ProviderApplicationNetworkPolicy(...$this->values());
        $operator=new ProviderApplicationOperator($registry,new ProviderApplicationReconciler($registry,$inspector),$never,$never,$never,
            inspectRuntime:static fn()=>['runtime_state'=>'RUNNING','pid'=>123], mutationGuard:$policy->assertMutable(...));
        foreach(['start','stop']as$action){
            try{
                if($action==='start')$operator->start(1,'web',$image,new RegistryTransferAuth('test-token','http'));
                else $operator->stop(1,'web');
                self::fail('Protected mutation accepted');
            }catch(\RuntimeException $e){self::assertStringContainsString('protected by platform policy',$e->getMessage());}
            self::assertSame($before,$registry->get(1,'web'));
        }
        self::assertSame('RUNNING',$operator->inspect(1,'web')['runtime']['runtime_state']);
    }
}
