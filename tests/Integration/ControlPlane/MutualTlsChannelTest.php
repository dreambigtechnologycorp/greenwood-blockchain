<?php

declare(strict_types=1);
namespace Greenwood\Tests\Integration\ControlPlane;
use Greenwood\ControlPlane\MutualTlsChannel;
use PHPUnit\Framework\TestCase;

final class MutualTlsChannelTest extends TestCase
{
    private static string $dir;
    private static array $pins=[];
    public static function setUpBeforeClass(): void
    {
        self::$dir=sys_get_temp_dir().'/gw-g2-tls-'.bin2hex(random_bytes(8)); mkdir(self::$dir,0700);
        $config=self::$dir.'/openssl.cnf';
        file_put_contents($config,"[req]\ndistinguished_name=dn\n[dn]\n[v3_ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n[leaf]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth,clientAuth\nsubjectAltName=DNS:provider-a.gw.test\n");
        $options=['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA,'digest_alg'=>'sha256','config'=>$config];
        $caKey=openssl_pkey_new($options); $csr=openssl_csr_new(['commonName'=>'G2 test CA'],$caKey,$options);
        $ca=openssl_csr_sign($csr,null,$caKey,1,$options+['x509_extensions'=>'v3_ca'],1);
        openssl_x509_export_to_file($ca,self::$dir.'/ca.pem');
        foreach (['provider','edge','stranger','deployment','expired','untrusted'] as $i=>$name) {
            $uriName=match($name){'provider'=>'provider/node-1','deployment'=>'deployment-controller',default=>'edge-controller'};
            $nameForTls=$name==='provider'?'provider-a.gw.test':$name.'.gw.test';
            $configText=file_get_contents($config);
            $configText=preg_replace('/subjectAltName=DNS:[^\n]+/', 'subjectAltName=DNS:'.$nameForTls.',URI:spiffe://greenwood-physical-v1/control-plane/'.$uriName, $configText);
            file_put_contents($config,$configText);
            $key=openssl_pkey_new($options); $csr=openssl_csr_new(['commonName'=>$name],$key,$options);
            $cert=openssl_csr_sign($csr,$name==='untrusted'?null:$ca,$name==='untrusted'?$key:$caKey,$name==='expired'?0:1,$options+['x509_extensions'=>'leaf'],$i+2);
            openssl_x509_export_to_file($cert,self::$dir.'/'.$name.'.pem');
            openssl_pkey_export_to_file($key,self::$dir.'/'.$name.'.key',null,$options);
            chmod(self::$dir.'/'.$name.'.key',0600);
            self::$pins[$name]=openssl_x509_fingerprint($cert,'sha256');
        }
    }
    public static function tearDownAfterClass(): void { foreach(glob(self::$dir.'/*') as $p) unlink($p); rmdir(self::$dir); }
    private function channel(string $name, array $peers): MutualTlsChannel {
        $uris=[];
        foreach($peers as $pin=>$principal) {
            $certName=array_search($pin,self::$pins,true);
            $uris[$pin]='spiffe://greenwood-physical-v1/control-plane/'.match($certName){'provider'=>'provider/node-1','deployment'=>'deployment-controller',default=>'edge-controller'};
        }
        $revocations=self::$dir.'/revocations.json';
        if(!is_file($revocations)) file_put_contents($revocations,json_encode(['valid_until'=>time()+3600,'revoked_sha256'=>[]]));
        return new MutualTlsChannel(self::$dir.'/ca.pem',self::$dir.'/'.$name.'.pem',self::$dir.'/'.$name.'.key',$peers,$uris,$revocations);
    }
    private function exchange(string $client='edge',string $serverIdentity='provider-a',string $peerName='provider-a.gw.test',bool $wrongServerPin=false,bool $registerClient=false): array
    {
        $server=$this->channel('provider',[self::$pins[$registerClient?$client:'edge']=>'edge-controller']);
        $listener=$server->listen('tcp://127.0.0.1:0'); $endpoint='tcp://'.stream_socket_get_name($listener,false);
        $pid=pcntl_fork();
        if($pid===0){
            try { $server->serveOne($listener,static fn($principal,$message)=>['principal'=>$principal,'received'=>$message]); fclose($listener); exit(0); }
            catch(\Throwable){ fclose($listener); exit(2); }
        }
        fclose($listener);
        try {
            $channel=$this->channel($client,[$wrongServerPin?self::$pins['stranger']:self::$pins['provider']=>'provider-a']);
            return $channel->request($endpoint,$peerName,$serverIdentity,['operation'=>'observe']);
        } finally { pcntl_waitpid($pid,$status); }
    }
    public function testRealMutualTlsAuthenticatesBothPeers(): void {
        $response=$this->exchange(); self::assertSame('edge-controller',$response['principal']); self::assertSame(['operation'=>'observe'],$response['received']);
    }
    public function testControllerToEdgeToProviderUsesRealMutualTlsForBothHops(): void
    {
        $dir=self::$dir.'/route-test'; mkdir($dir,0700);
        $providerId=str_repeat('a',64);
        $providerChannel=$this->channel('provider',[self::$pins['edge']=>'edge-controller']);
        $providerListener=$providerChannel->listen('tcp://127.0.0.1:0');
        $assignment=['application_id'=>1,'service'=>'web','release_id'=>4,'provider_id'=>$providerId,
            'provider_address'=>'10.77.0.100','ingress_port'=>20000,'instance_id'=>'app-1-instance',
            'image_digest'=>'sha256:'.str_repeat('b',64),'provider_endpoint'=>'tcp://'.stream_socket_get_name($providerListener,false),'provider_tls_name'=>'provider-a.gw.test'];
        file_put_contents($dir.'/1-web.json',json_encode($assignment)); chmod($dir.'/1-web.json',0600);
        $store=new \Greenwood\Edge\ApprovedDeploymentStore($dir);
        $service=new \Greenwood\ControlPlane\ProviderObservationService($providerId,'10.77.0.100',['edge-controller'],$store,static fn()=>[
            'record'=>['state'=>'RUNNING','container_id'=>'app-1-instance','image_digest'=>'sha256:'.str_repeat('b',64)],
            'runtime'=>['runtime_state'=>'RUNNING','pid'=>123],
            'network'=>['state'=>'HEALTHY','ready'=>true,'pid'=>123,'namespace_identity'=>'fixture-namespace','ingress_port'=>20000],
        ]);
        $providerPid=pcntl_fork();
        if($providerPid===0){try{$providerChannel->serveOne($providerListener,$service->handle(...));exit(0);}catch(\Throwable){exit(2);}}
        fclose($providerListener);
        $edgeChannel=$this->channel('edge',[self::$pins['deployment']=>'deployment-controller',self::$pins['provider']=>$providerId]);
        $edgeListener=$edgeChannel->listen('tcp://127.0.0.1:0');$edgeEndpoint='tcp://'.stream_socket_get_name($edgeListener,false);
        $edgePid=pcntl_fork();
        if($edgePid===0){
            try {
                $controller=new \Greenwood\Edge\AuthenticatedRouteController(new \Greenwood\Edge\EdgeRouteRegistry($dir,'greenwoodblockchain.com'),$store,
                    static fn($p)=>$p==='deployment-controller', \Greenwood\Edge\AuthenticatedRouteController::providerObserver($edgeChannel));
                $edgeChannel->serveOne($edgeListener,$controller->handle(...));exit(0);
            }catch(\Throwable){exit(2);}
        }
        fclose($edgeListener);
        try {
            $client=$this->channel('deployment',[self::$pins['edge']=>'edge-controller']);
            $response=$client->request($edgeEndpoint,'edge.gw.test','edge-controller',['action'=>'publish','application_id'=>1,'service'=>'web','release_id'=>4]);
            self::assertSame('gw-1-web.greenwoodblockchain.com',$response['hostname']);
            self::assertSame('PUBLISHED',$response['desired']);self::assertSame('HEALTHY',$response['state']);self::assertSame('UNKNOWN',$response['proxy_state']);
        }finally{
            pcntl_waitpid($providerPid,$providerStatus);pcntl_waitpid($edgePid,$edgeStatus);
            foreach(glob($dir.'/*') as $p) unlink($p);rmdir($dir);
        }
        self::assertSame(0,pcntl_wexitstatus($providerStatus));self::assertSame(0,pcntl_wexitstatus($edgeStatus));
    }

    public function testClientWithoutCertificateNeverReachesHandler(): void {
        $server=$this->channel('provider',[self::$pins['edge']=>'edge-controller']);
        $listener=$server->listen('tcp://127.0.0.1:0');$endpoint='tcp://'.stream_socket_get_name($listener,false);
        $pid=pcntl_fork();
        if($pid===0){try{$server->serveOne($listener,static fn()=>throw new \LogicException('Handler reached'));exit(3);}catch(\LogicException){exit(4);}catch(\Throwable){exit(2);}}
        fclose($listener);
        try {
            $context=stream_context_create(['ssl'=>['cafile'=>self::$dir.'/ca.pem','verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>'provider-a.gw.test']]);
            $stream=stream_socket_client($endpoint,$errno,$error,5,STREAM_CLIENT_CONNECT,$context);
            stream_set_timeout($stream,5);
            @stream_socket_enable_crypto($stream,true,STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            @fwrite($stream,pack('N',2).'{}');@fread($stream,1);fclose($stream);
        } finally {pcntl_waitpid($pid,$status);}
        self::assertSame(2,pcntl_wexitstatus($status));
    }
    public function testExpiredRegisteredCertificateIsRejected(): void {
        usleep(1100000); // The test leaf has a zero-day validity window.
        $this->expectException(\RuntimeException::class); $this->exchange(client:'expired',registerClient:true);
    }
    public function testUntrustedRegisteredCertificateIsRejected(): void {
        $this->expectException(\RuntimeException::class); $this->exchange(client:'untrusted',registerClient:true);
    }
    public function testStaleRevocationPolicyFailsClosed(): void {
        file_put_contents(self::$dir.'/revocations.json',json_encode(['valid_until'=>time()-1,'revoked_sha256'=>[]]));
        try { $this->expectException(\RuntimeException::class); $this->exchange(); }
        finally { unlink(self::$dir.'/revocations.json'); }
    }
    public function testRevokedCertificateIsRejected(): void {
        file_put_contents(self::$dir.'/revocations.json',json_encode(['valid_until'=>time()+3600,'revoked_sha256'=>[self::$pins['provider']]]));
        try { $this->expectExceptionMessage('Revoked control-plane certificate'); $this->exchange(); }
        finally { unlink(self::$dir.'/revocations.json'); }
    }
    public function testWrongUriIdentityIsRejected(): void {
        $server=$this->channel('provider',[self::$pins['edge']=>'edge-controller']);
        $listener=$server->listen('tcp://127.0.0.1:0');$endpoint='tcp://'.stream_socket_get_name($listener,false);
        $pid=pcntl_fork();
        if($pid===0){try{$server->serveOne($listener,static fn()=>[]);exit(0);}catch(\Throwable){exit(2);}}
        fclose($listener);
        try {
            $client=new MutualTlsChannel(self::$dir.'/ca.pem',self::$dir.'/edge.pem',self::$dir.'/edge.key',
                [self::$pins['provider']=>'provider-a'],[self::$pins['provider']=>'spiffe://greenwood-physical-v1/control-plane/provider/node-2'],self::$dir.'/revocations.json');
            $this->expectExceptionMessage('Unexpected control-plane URI identity');
            $client->request($endpoint,'provider-a.gw.test','provider-a',[]);
        } finally {pcntl_waitpid($pid,$status);}
    }
    public function testSameCaButUnregisteredClientIsRejected(): void {
        $this->expectException(\RuntimeException::class); $this->exchange('stranger');
    }
    public function testWrongProviderIdentityIsRejected(): void {
        $this->expectExceptionMessage('Unexpected control-plane peer'); $this->exchange(serverIdentity:'provider-b');
    }
    public function testWrongProviderCertificatePinIsRejected(): void {
        $this->expectExceptionMessage('Unregistered control-plane certificate'); $this->exchange(wrongServerPin:true);
    }
    public function testWrongServerNameIsRejected(): void {
        $this->expectExceptionMessage('TLS authentication failed'); $this->exchange(peerName:'wrong.gw.test');
    }
}
