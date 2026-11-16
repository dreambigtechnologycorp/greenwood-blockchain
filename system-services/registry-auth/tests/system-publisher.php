<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Greenwood\SystemServices\RegistryAuth\SystemServicePublisher;
use Greenwood\Economics\CanonicalJson;
use Greenwood\Crypto\ProtocolHash;
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$issuer=sodium_crypto_sign_keypair();$key=sodium_crypto_sign_keypair();$public=bin2hex(sodium_crypto_sign_publickey($key));$genesis=str_repeat('a',64);
$policy=['version'=>1,'enabled'=>true,'chain_id'=>'release-fixture','genesis_hash'=>$genesis,'authorization_hash'=>str_repeat('b',64),'expires_at'=>2000,'publishers'=>[$public=>['metering']]];
$service=new SystemServicePublisher($db,$issuer,$policy,'release-fixture',$genesis);
$request=['version'=>1,'chain_id'=>'release-fixture','genesis_hash'=>$genesis,'policy_hash'=>hash('sha256',CanonicalJson::encode($policy)),'repository'=>'chains/release-fixture/system-services/metering','actions'=>['pull','push'],'nonce'=>str_repeat('c',64),'issued_at'=>1000,'expires_at'=>1100];
$sign=fn($r)=>bin2hex(sodium_crypto_sign_detached(ProtocolHash::hash('GREENWOOD_SYSTEM_SERVICE_PUBLISH_V1',CanonicalJson::encode($r)),sodium_crypto_sign_secretkey($key)));
$checks=0;$deny=static function($f)use(&$checks){try{$f();}catch(Throwable){$checks++;return;}throw new RuntimeException('Expected publisher rejection');};
$result=$service->authorize($request,$public,$sign($request),1001);$parts=explode('.',$result['token']);$claims=json_decode(base64_decode(strtr($parts[1],'-_','+/')),true);if($claims['access'][0]['name']!==$request['repository']||!sodium_crypto_sign_verify_detached(base64_decode(strtr($parts[2],'-_','+/')),$parts[0].'.'.$parts[1],sodium_crypto_sign_publickey($issuer)))throw new RuntimeException('Invalid scoped issuer token');$checks++;
$deny(fn()=>$service->authorize($request,$public,$sign($request),1002));
foreach(['repository'=>'chains/release-fixture/registries/'.str_repeat('d',64).'/apps/1/web','genesis_hash'=>str_repeat('e',64),'policy_hash'=>str_repeat('f',64),'actions'=>['pull','push','delete'],'expires_at'=>1201] as $field=>$value){$bad=$request;$bad['nonce']=bin2hex(random_bytes(32));$bad[$field]=$value;$deny(fn()=>$service->authorize($bad,$public,$sign($bad),1002));}
$deny(fn()=>$service->authorize($request,$public,$sign($request),1100));$deny(fn()=>$service->authorize($request,str_repeat('1',64),$sign($request),1001));
$bad=$policy;$bad['enabled']=false;$deny(fn()=>new SystemServicePublisher($db,$issuer,$bad,'release-fixture',$genesis));
echo json_encode(['status'=>'PASS','checks'=>$checks,'application_permissions_used'=>false,'production_publication'=>false],JSON_PRETTY_PRINT)."\n";
