<?php
declare(strict_types=1);
namespace Greenwood\Tests\Unit\Provider;
use Greenwood\Provider\ProviderApplicationBindingRegistry;
use Greenwood\Compute\Containerd\OciImageRuntimeConfig;
use PHPUnit\Framework\TestCase;
final class ProviderApplicationBindingRegistryTest extends TestCase
{
    private string $dir;
    protected function setUp():void{$this->dir=sys_get_temp_dir().'/gw-bind-'.bin2hex(random_bytes(8));}
    protected function tearDown():void{foreach(glob($this->dir.'/*')?:[]as$p)unlink($p);if(is_dir($this->dir))rmdir($this->dir);}
    private function registry():ProviderApplicationBindingRegistry{return new ProviderApplicationBindingRegistry($this->dir,'10.254.16.0/28',20000,20003);}
    private function image(array $ports=['8080/tcp']):OciImageRuntimeConfig{return new OciImageRuntimeConfig(['/bin/app'],[],'/',$ports);}
    public function testReservationsPersistAndRemainStable():void
    {$a=$this->registry()->reserve(1,'web',$this->image());self::assertSame($a,$this->registry()->reserve(1,'web',$this->image()));self::assertSame('RESERVED',$a['state']);self::assertSame('10.254.16.2/30',$a['application_address']);self::assertSame(20000,$a['ingress_port']);}
    public function testServicesReceiveDistinctPortsAndSubnets():void
    {$r=$this->registry();$a=$r->reserve(1,'web',$this->image());$b=$r->reserve(2,'web',$this->image());self::assertNotSame($a['ingress_port'],$b['ingress_port']);self::assertNotSame($a['application_address'],$b['application_address']);self::assertNotSame($a['host_veth'],$b['host_veth']);}
    public function testExhaustionDoesNotReuseLiveReservation():void
    {$r=$this->registry();foreach(range(1,4)as$id)$r->reserve($id,'web',$this->image());$this->expectExceptionMessage('capacity exhausted');$r->reserve(5,'web',$this->image());}
    public function testAmbiguousImagePortIsRejected():void
    {$this->expectExceptionMessage('exactly one');$this->registry()->reserve(1,'web',$this->image(['80/tcp','8080/tcp']));}
    public function testChangedPolicyRequiresMigration():void
    {$this->registry()->reserve(1,'web',$this->image());$this->expectExceptionMessage('migration required');new ProviderApplicationBindingRegistry($this->dir,'10.254.32.0/28',20000,20003);}
    public function testPortDriftCannotSilentlyChangeExistingBinding():void
    {$r=$this->registry();$r->reserve(1,'web',$this->image());$this->expectExceptionMessage('reconciliation required');$r->reserve(1,'web',$this->image(['80/tcp']));}
}
