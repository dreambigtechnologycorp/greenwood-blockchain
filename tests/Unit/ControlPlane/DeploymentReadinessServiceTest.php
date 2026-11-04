<?php
declare(strict_types=1);
use Greenwood\ControlPlane\DeploymentReadinessService;
use PHPUnit\Framework\TestCase;
final class DeploymentReadinessServiceTest extends TestCase
{
    private function runScenario(string $fault='',int $application=2,?\Closure $url=null,int $minimum=2):array {
        $request=['application_id'=>$application,'service'=>'web','release_id'=>2,'image_digest'=>'sha256:'.str_repeat('a',64),'nonce'=>str_repeat('b',64)];
        $a=$request; unset($a['nonce']);
        $a+=['assignment_id'=>str_repeat('c',64),'provider_id'=>str_repeat('d',64),'instance_id'=>'app-2-web','provider_address'=>'10.77.0.100','ingress_port'=>21000];
        $reads=0;$auths=0;
        $s=new DeploymentReadinessService(
            static function()use($a,$fault,&$reads):array {++$reads;return ['assignment'=>$a,'generation'=>($fault==='changed'&&$reads>1?'changed':str_repeat('e',64))];},
            static function()use($fault,&$auths):array {++$auths;return ['authorized'=>$fault!=='unauthorized'&&!($fault==='revoked'&&$auths>1),'authority_epoch'=>1];},
            static function($a,$nonce)use($fault):array {return array_replace($a,[
                'nonce'=>$fault==='replay'?'old':$nonce,'address'=>$a['provider_address'],'port'=>$a['ingress_port'],
                'ready'=>$fault!=='unhealthy','state'=>'RUNNING','lifecycle_state'=>'RUNNING','network_state'=>'HEALTHY',
                'pid'=>123,'network_pid'=>$fault==='pid'?124:123,'namespace_identity'=>'net:123',
                'observed_at'=>$fault==='stale'?900:1000,'image_digest'=>$fault==='digest'?'wrong':$a['image_digest']]);},
            static fn()=>['verified'=>$fault!=='public'],static fn()=>1000,$url,$minimum
        );
        return $s->inspect($request);
    }
    public function testFreshBoundReadiness():void{$r=$this->runScenario();self::assertTrue($r['ready']);self::assertSame('https://gw-2-web.greenwoodblockchain.com/',$r['canonical_url']);self::assertSame(1015,$r['expires_at']);}
    public function testExplicitH8AppOneUrl():void {
        $r=$this->runScenario('',1,static fn()=>'https://app-1.greenwoodblockchain.com/',1);
        self::assertTrue($r['ready']);self::assertSame('https://app-1.greenwoodblockchain.com/',$r['canonical_url']);
    }
    public function testLegacyDefaultStillRejectsAppOne():void {
        $this->expectException(InvalidArgumentException::class);$this->runScenario('',1);
    }
    public function testWrongApplicationHostnameRejected():void {
        $this->expectException(RuntimeException::class);
        $this->runScenario('',1,static fn()=>'https://app-2.greenwoodblockchain.com/',1);
    }
    public function testUnsafeObservationsFailClosed():void {
        foreach(['changed','unauthorized','revoked','replay','unhealthy','pid','stale','digest','public']as$fault){
            try{$this->runScenario($fault);self::fail('Accepted '.$fault);}catch(RuntimeException $e){self::assertNotSame('',$e->getMessage());}
        }
    }
}
