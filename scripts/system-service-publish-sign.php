#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__.'/economics-bootstrap.php';
use Greenwood\Economics\CanonicalJson;
use Greenwood\Crypto\{Ed25519KeyPair,ProtocolHash};
try {
    if($argc!==4)throw new RuntimeException('Usage: system-service-publish-sign.php POLICY_JSON SERVICE PRIVATE_KEY');
    $raw=file_get_contents($argv[1],false,null,0,32769);if($raw===false||strlen($raw)>32768)throw new RuntimeException('Bounded publisher policy required');$policy=json_decode($raw,true,24,JSON_THROW_ON_ERROR);
    $path=$argv[3];if(realpath($path)!==$path||!is_file($path)||fileowner($path)!==posix_geteuid()||(fileperms($path)&0077)||filesize($path)!==96)throw new RuntimeException('Explicit private publisher key required');
    $key=Ed25519KeyPair::fromBytes(file_get_contents($path));$public=bin2hex($key->getPublicKey());$now=time();
    if(($policy['enabled']??false)!==true||!in_array($argv[2],$policy['publishers'][$public]??[],true)||!is_int($policy['expires_at']??null)||$policy['expires_at']<=$now)throw new RuntimeException('Current explicit system-service publisher grant required');
    $request=['version'=>1,'chain_id'=>$policy['chain_id'],'genesis_hash'=>$policy['genesis_hash'],'policy_hash'=>hash('sha256',CanonicalJson::encode($policy)),'repository'=>'chains/'.$policy['chain_id'].'/system-services/'.$argv[2],'actions'=>['pull','push'],'nonce'=>bin2hex(random_bytes(32)),'issued_at'=>$now,'expires_at'=>min($now+120,$policy['expires_at'])];
    echo json_encode(['request'=>$request,'public_key'=>$public,'signature'=>bin2hex($key->sign(ProtocolHash::hash('GREENWOOD_SYSTEM_SERVICE_PUBLISH_V1',CanonicalJson::encode($request))))],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
