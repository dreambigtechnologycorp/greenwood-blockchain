<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Greenwood\ControlPlane\MutualTlsChannel;
use Greenwood\ControlPlane\SignedPolicySnapshot;
use Greenwood\DeploymentController\Assignment\{AssignmentReceiver,AssignmentDistributor,AssignmentEndpoint};
function check(bool $v,string $m):void {if(!$v)throw new RuntimeException($m);}
$root=sys_get_temp_dir().'/gw-distribution-tls-'.bin2hex(random_bytes(8));mkdir($root,0700);umask(0077);
$base="[req]\ndistinguished_name=dn\n[dn]\n[v3_ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n[leaf]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth,clientAuth\n";
$config=$root.'/openssl.cnf';file_put_contents($config,$base);
$options=['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA,'digest_alg'=>'sha256','config'=>$config];
$caKey=openssl_pkey_new($options);$csr=openssl_csr_new(['commonName'=>'isolated distribution test CA'],$caKey,$options);
$ca=openssl_csr_sign($csr,null,$caKey,1,$options+['x509_extensions'=>'v3_ca'],1);openssl_x509_export_to_file($ca,$root.'/ca.pem');
$pins=[];$roles=['controller'=>'deployment-controller','node-1'=>'assignment/node-1','node-4'=>'assignment/node-4','stranger'=>'deployment-controller'];
try {
    foreach($roles as$name=>$role){
        file_put_contents($config,$base.'subjectAltName=DNS:'.$name.'.test,URI:spiffe://greenwood-physical-v1/control-plane/'.$role."\n");
        $key=openssl_pkey_new($options);$csr=openssl_csr_new(['commonName'=>$name],$key,$options);
        $cert=openssl_csr_sign($csr,$ca,$caKey,1,$options+['x509_extensions'=>'leaf'],count($pins)+2);
        openssl_x509_export_to_file($cert,$root.'/'.$name.'.pem');openssl_pkey_export_to_file($key,$root.'/'.$name.'.key',null,$options);chmod($root.'/'.$name.'.key',0600);
        $pins[$name]=openssl_x509_fingerprint($cert,'sha256');
    }
    $revocations=$root.'/revocations.json';$policyKey=sodium_crypto_sign_keypair();$revision=0;
    $publish=static function(array $revoked=[],bool $expired=false,bool $tampered=false)use($policyKey,$revocations,&$revision):void{
        $payload=['schema_version'=>1,'purpose'=>'revocations','chain_id'=>'greenwood-physical-v1','revision'=>++$revision,
            'issued_at'=>time()-1,'valid_until'=>$expired?time()-1:time()+120,'revoked_sha256'=>$revoked];
        $signature=bin2hex(sodium_crypto_sign_detached(SignedPolicySnapshot::signingBytes($payload),sodium_crypto_sign_secretkey($policyKey)));
        if($tampered)$payload['revision']++;
        file_put_contents($revocations,json_encode(['payload'=>$payload,'signature'=>$signature]));
    };
    $publish();
    foreach(['node-1','node-4']as$n){mkdir($root.'/'.$n,0700);mkdir($root.'/'.$n.'/assignments',0700);}
    $channel=static function($name,$peers)use($root,$roles,$pins,$revocations,$policyKey){$identities=[];$uris=[];foreach($peers as$peer=>$identity){$identities[$pins[$peer]]=$identity;$uris[$pins[$peer]]='spiffe://greenwood-physical-v1/control-plane/'.$roles[$peer];}return new MutualTlsChannel($root.'/ca.pem',$root.'/'.$name.'.pem',$root.'/'.$name.'.key',$identities,$uris,$revocations,
        new SignedPolicySnapshot($revocations,$root.'/'.$name.'-policies.sqlite','revocations',bin2hex(sodium_crypto_sign_publickey($policyKey)),300));};
    $clientName='controller';$wrongIdentity=false;
    $send=static function($node,$request)use($channel,$root,&$clientName,&$wrongIdentity):array {
        $server=$channel($node,['controller'=>'deployment-controller']);$listener=$server->listen('tcp://127.0.0.1:0');$address='tcp://'.stream_socket_get_name($listener,false);
        $pid=pcntl_fork();if($pid<0)throw new RuntimeException('fork failed');
        if($pid===0){try{
            $receiver=new AssignmentReceiver($node,$root.'/'.$node.'/assignments',$root.'/'.$node.'/ledger.sqlite',static fn()=>true,static fn()=>true);
            (new AssignmentEndpoint($server,$receiver))->serveOne($listener);fclose($listener);exit(0);
        }catch(Throwable){fclose($listener);exit(2);}}
        fclose($listener);
        try{return AssignmentDistributor::mtls($channel($clientName,[$node=>$node]),[$node=>['endpoint'=>$address,'tls_name'=>$node.'.test','identity'=>$wrongIdentity?'other-node':$node]])($node,$request);}
        finally{pcntl_waitpid($pid,$status);}
    };
    $a=['chain_id'=>'greenwood-physical-v1','registry_contract_id'=>\Greenwood\DeploymentController\Auth\DeploymentIdentity::REGISTRY,'application_id'=>1,'service'=>'web','release_id'=>1,'provider_id'=>str_repeat('a',64),'provider_address'=>'10.77.0.100','ingress_port'=>21001,'instance_id'=>'app-1-web','image_digest'=>'sha256:'.str_repeat('b',64),'provider_endpoint'=>'tcp://10.77.0.100:19443','provider_tls_name'=>'provider.test'];
    $d=['chain_id'=>'greenwood-physical-v1','registry_contract_id'=>\Greenwood\DeploymentController\Auth\DeploymentIdentity::REGISTRY,'operation_id'=>str_repeat('c',32),'account_id'=>str_repeat('d',64),'version'=>1,'previous_generation'=>null,'assignment'=>$a];
    $db=new PDO('sqlite:'.$root.'/distribution.sqlite');$distributor=new AssignmentDistributor($db,['node-1','node-4'],static fn()=>true,$send);
    $ack=$distributor->distribute($d);check($ack['acknowledged']&&count($ack['nodes'])===2,'TLS distribution failed');
    foreach(['unregistered','wrong-identity','revoked','expired-policy','tampered-policy']as$case){
        $clientName=$case==='unregistered'?'stranger':'controller';$wrongIdentity=$case==='wrong-identity';
        $publish($case==='revoked'?[$pins['controller']]:[],$case==='expired-policy',$case==='tampered-policy');
        $failed=false;try{$distributor->distribute($d);}catch(Throwable){$failed=true;}
        check($failed,'Accepted '.$case);check($db->query('SELECT state FROM distribution')->fetchColumn()==='PENDING','Failed identity retained admission');
    }
    echo "Real mTLS assignment distribution: dedicated receiver identities, both ACKs, unregistered client, wrong receiver, revoked controller, expired and tampered signed policy passed\n";
}finally{
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as$p){if($p->isDir())rmdir($p->getPathname());else unlink($p->getPathname());}rmdir($root);
}
