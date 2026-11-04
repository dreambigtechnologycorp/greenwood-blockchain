<?php
declare(strict_types=1);
namespace Greenwood\Tests\Unit\Edge;
use Greenwood\Edge\{ApacheApplyAdapter,ApacheIngressConfiguration};
use PHPUnit\Framework\TestCase;
final class ApacheApplyAdapterTest extends TestCase {
    private string $dir;private string $file;private string $live='';private int $reloads=0;private int $failReloads=0;private bool $valid=true;private bool $probes=true;
    protected function setUp():void{$this->dir=sys_get_temp_dir().'/gw-apache-'.bin2hex(random_bytes(6));mkdir($this->dir,0700);$this->file=$this->dir.'/managed.conf';}
    protected function tearDown():void{foreach(glob($this->dir.'/*')as$f)unlink($f);rmdir($this->dir);}
    private function route(int$id=1):array{return ['hostname'=>'gw-'.$id.'-web.greenwoodblockchain.com','application_id'=>$id,'service'=>'web','release_id'=>4,'assignment_id'=>str_repeat((string)$id,64),'desired'=>'PUBLISHED','state'=>'HEALTHY','binding'=>['address'=>'10.77.0.100','port'=>18089,'image_digest'=>'sha256:'.str_repeat('a',64),'instance_id'=>'instance']];}
    private function adapter(?\Closure$checkpoint=null):ApacheApplyAdapter{return new ApacheApplyAdapter($this->dir,$this->file,new ApacheIngressConfiguration(),fn()=> $this->valid,fn()=> $this->valid,function(){++$this->reloads;if($this->failReloads>0){--$this->failReloads;return false;}$this->live=file_get_contents($this->file);return true;},function($routes,$withdrawn,$marker){return ['ok'=>$this->probes&&str_contains($this->live,'greenwood-generation-'.$marker),'actual_generation_config'=>$this->live];},$checkpoint);}
    public function testConfirmedGenerationIsIdempotentButStillProbed():void{
        $first=$this->adapter()->apply([$this->route()]);self::assertTrue($first['confirmed']);self::assertSame('CONFIRMED',$first['status']);self::assertSame(hash_file('sha256',$this->file),$first['generation']);
        $second=$this->adapter()->apply([$this->route()]);self::assertTrue($second['idempotent']);self::assertSame(1,$this->reloads);
        $this->probes=false;$third=$this->adapter()->apply([$this->route()]);self::assertFalse($third['confirmed']);self::assertGreaterThan(1,$this->reloads);
    }
    public function testInvalidCandidateNeverDisplacesEffectiveGeneration():void{
        $first=$this->adapter()->apply([$this->route()]);$bytes=file_get_contents($this->file);$this->valid=false;
        $result=$this->adapter()->apply([$this->route(),$this->route(2)]);self::assertSame('REJECTED',$result['status']);self::assertSame($bytes,file_get_contents($this->file));self::assertSame(1,$this->reloads);self::assertSame($first['generation'],$result['effective']['generation']);
    }
    public function testFailedPublicationRestoresOnlyStillAuthorizedGeneration():void{
        $first=$this->adapter()->apply([$this->route()]);$this->failReloads=1;
        $result=$this->adapter()->apply([$this->route(),$this->route(2)]);self::assertFalse($result['confirmed']);self::assertSame('ROLLED_BACK_VERIFIED',$result['status']);self::assertSame($first['generation'],hash_file('sha256',$this->file));self::assertTrue($result['rollback_probe']['ok']);
    }
    public function testFailedSecurityWithdrawalDoesNotRestoreExposedRoute():void{
        $this->adapter()->apply([$this->route()]);$exposed=$this->live;$this->failReloads=1;$withdrawn=$this->route();$withdrawn['desired']='WITHDRAWN';
        $result=$this->adapter()->apply([$withdrawn]);self::assertSame('SECURITY_WITHDRAWAL_UNCONFIRMED',$result['status']);self::assertFalse($result['confirmed']);self::assertSame($exposed,$this->live);self::assertStringNotContainsString('ProxyPass ',file_get_contents($this->file));self::assertFalse($result['observed_after_failure']['ok']);
        $retry=$this->adapter()->apply([$withdrawn]);self::assertTrue($retry['confirmed']);self::assertStringNotContainsString('ProxyPass ',$this->live);
    }
    public function testProcessDeathAfterActivationIsRecoveredWithoutTrustingJournal():void{
        $this->adapter()->apply([]);$pid=pcntl_fork();
        if($pid===0){$this->adapter(static function($point){if($point==='activated')exit(77);})->apply([$this->route()]);exit(78);}
        pcntl_waitpid($pid,$status);self::assertSame(77,pcntl_wexitstatus($status));self::assertSame('ACTIVATED_UNCONFIRMED',$this->adapter()->state()['status']);self::assertStringNotContainsString('ProxyPass ',$this->live);
        $result=$this->adapter()->apply([$this->route()]);self::assertTrue($result['confirmed']);self::assertFalse($result['idempotent']);self::assertStringContainsString('ProxyPass ',$this->live);
    }
    public function testUnconfirmedReloadedGenerationIsConsideredExposedOnWithdrawal():void{
        $this->adapter()->apply([]);$pid=pcntl_fork();
        if($pid===0){$this->adapter(static function($point){if($point==='reloaded')exit(77);})->apply([$this->route()]);exit(78);}
        pcntl_waitpid($pid,$status);self::assertSame(77,pcntl_wexitstatus($status));
        self::assertSame('RELOADED_UNCONFIRMED',$this->adapter()->state()['status']);
        $this->live=file_get_contents($this->file);$this->failReloads=1;$r=$this->route();$r['desired']='WITHDRAWN';
        $result=$this->adapter()->apply([$r]);self::assertSame('SECURITY_WITHDRAWAL_UNCONFIRMED',$result['status']);self::assertFalse($result['confirmed']);self::assertArrayNotHasKey('rollback_probe',$result);
        $this->failReloads=1;$again=$this->adapter()->apply([$r]);self::assertSame('SECURITY_WITHDRAWAL_UNCONFIRMED',$again['status']);
    }
    public function testChangedAssignmentCannotRollbackToOldBackend():void{
        $this->adapter()->apply([$this->route()]);$next=$this->route();$next['assignment_id']=str_repeat('f',64);$next['binding']['port']=18090;$this->failReloads=1;
        $r=$this->adapter()->apply([$next]);self::assertSame('SECURITY_WITHDRAWAL_UNCONFIRMED',$r['status']);self::assertArrayNotHasKey('rollback_probe',$r);
    }
    public function testRendererRejectsInjectedHostnameBeforeEffects():void{
        $r=$this->route();$r['hostname'].="\nInclude /tmp/evil";$this->expectException(\InvalidArgumentException::class);$this->adapter()->apply([$r]);
    }
}
