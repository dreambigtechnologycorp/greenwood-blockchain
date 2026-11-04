<?php
declare(strict_types=1);
namespace Greenwood\Tests\Operator;
use Greenwood\Rpc\{OperatorApi,OperatorAuth,ProviderApplicationHttpApi};
use PHPUnit\Framework\TestCase;
final class ProviderApplicationHttpApiTest extends TestCase
{
    private string $file;
    private ProviderApplicationHttpApi $api;
    private int $calls=0;
    private array $headers=['authorization'=>'Bearer test-operator-token-0123456789'];
    protected function setUp():void
    {
        $this->file=tempnam(sys_get_temp_dir(),'gw-app-auth-');chmod($this->file,0600);
        file_put_contents($this->file,'test-operator-token-0123456789');
        $auth=OperatorAuth::fromFile($this->file);
        $call=function(...$args):array{$this->calls++;return ['ok'=>true];};
        $operator=new OperatorApi($auth,providerStatus:$call,providerDrain:$call,
            providerApplicationStart:$call,providerApplicationInspect:$call,providerApplicationStop:$call,providerApplicationReserve:$call);
        $this->api=new ProviderApplicationHttpApi($operator,$auth,'10.77.0.103');
    }
    protected function tearDown():void{unlink($this->file);}
    public function testOnlyApplicationRoutesAreAvailable():void
    {
        foreach(['inspect','stop']as$action)self::assertSame(200,$this->api->handle('POST','/v1/operator/provider/applications/'.$action,'{"application_id":1,"service":"web"}',$this->headers,'10.77.0.103:45000')->status);
        foreach([['GET','/v1/operator/provider/status'],['POST','/v1/operator/provider/drain'],['POST','/v1/operator/provider/capability'],['POST','/v1/operator/provider/applications/start?x=1']]as[$method,$path])self::assertSame(404,$this->api->handle($method,$path,'{}',$this->headers,'10.77.0.103:45000')->status);
        self::assertSame(2,$this->calls);
    }
    public function testConnectorStillRequiresOperatorAuthentication():void
    {
        self::assertSame(401,$this->api->handle('GET','/v1/operator/provider/applications/inspect','',[],'10.77.0.103:45000')->status);
        self::assertSame(0,$this->calls);
    }
    public function testReservationRequiresPeerAuthAndOnlyImmutableIdentity():void
    {
        $path='/v1/operator/provider/applications/reserve';
        $p=['application_id'=>1,'service'=>'web','image_ref'=>'registry/apps/1/web@sha256:'.str_repeat('a',64)];
        self::assertSame(401,$this->api->handle('POST',$path,json_encode($p),[],'10.77.0.103:45000')->status);
        self::assertSame(403,$this->api->handle('POST',$path,json_encode($p),$this->headers,'10.77.0.100:45000')->status);
        foreach(['command','pull_token','ingress_port','instance_id']as$field)
            self::assertSame(400,$this->api->handle('POST',$path,json_encode($p+[$field=>'forbidden']),$this->headers,'10.77.0.103:45000')->status);
        $bad=$p;$bad['image_ref']='registry/apps/1/web:latest';
        self::assertSame(400,$this->api->handle('POST',$path,json_encode($bad),$this->headers,'10.77.0.103:45000')->status);
        self::assertSame(0,$this->calls);
        self::assertSame(200,$this->api->handle('POST',$path,json_encode($p),$this->headers,'10.77.0.103:45000')->status);
        self::assertSame(1,$this->calls);
    }
    public function testForwardedHeadersCannotSpoofConnector():void
    {
        self::assertSame(403,$this->api->handle('POST','/v1/operator/provider/applications/inspect','{}',$this->headers+['x-forwarded-for'=>'10.77.0.103'],'10.77.0.101:45000')->status);
        self::assertSame(0,$this->calls);
    }
    public function testExecutionFieldsRemainRejected():void
    {
        $p=['application_id'=>1,'service'=>'web','image_ref'=>'registry/apps/1/web@sha256:'.str_repeat('a',64),'pull_token'=>'test-pull','registry_scheme'=>'http'];
        foreach(['command','env','mounts','network','user','args','working_directory','host_ports']as$field){
            self::assertSame(400,$this->api->handle('POST','/v1/operator/provider/applications/start',json_encode($p+[$field=>'forbidden']),$this->headers,'10.77.0.103:45000')->status);
        }
        self::assertSame(0,$this->calls);
        self::assertSame(200,$this->api->handle('POST','/v1/operator/provider/applications/start',json_encode($p),$this->headers,'10.77.0.103:45000')->status);
        self::assertSame(1,$this->calls);
    }
    public function testBindMustBeExplicitPrivateIpv4():void
    {
        self::assertSame('http://10.77.0.100:8787',ProviderApplicationHttpApi::validateEndpoint('http://10.77.0.100:8787'));
        foreach(['http://0.0.0.0:8787','http://127.0.0.1:8787','http://8.8.8.8:8787','http://host:8787','http://10.77.0.100:8787/path','http://user@10.77.0.100:8787']as$url){
            try{ProviderApplicationHttpApi::validateEndpoint($url);self::fail('Invalid bind accepted');}catch(\InvalidArgumentException){self::assertTrue(true);}
        }
    }
}
