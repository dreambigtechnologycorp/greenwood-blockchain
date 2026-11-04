<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Greenwood\DeploymentController\Auth\{DeploymentIdentity,ChallengeService};
use Greenwood\DeploymentController\State\SqliteOperationStore;
function reject(Closure $call):void {try{$call();}catch(InvalidArgumentException){return;}throw new RuntimeException('Unsafe identity accepted');}
$dir=sys_get_temp_dir().'/gw-identity-'.bin2hex(random_bytes(8));mkdir($dir,0700);
try {
    $s=new SqliteOperationStore($dir.'/operations.sqlite');$c=new ChallengeService($s->db);
    $intent=['chain_id'=>DeploymentIdentity::CHAIN,'registry_contract_id'=>DeploymentIdentity::REGISTRY,'application_id'=>1,
        'action'=>'deploy','service'=>'web','release_number'=>1,'image_digest'=>'sha256:'.str_repeat('a',64),'idempotency_key'=>'same-number-test'];
    $challenge=$c->issue($intent);
    if(!str_starts_with($challenge['canonical_bytes'],"GREENWOOD-DEPLOYMENT-AUTH-V2\nchain_id=".DeploymentIdentity::CHAIN."\nregistry_contract_id=".DeploymentIdentity::REGISTRY."\n"))throw new RuntimeException('Signature domain omitted registry');
    foreach(['77e7bbde37f96369591a49fa63b5ce5b0d4e67c3bc98fd8c8fbabdff815d1715',str_repeat('f',64),null]as$id){
        $bad=$intent;$bad['registry_contract_id']=$id;reject(fn()=>$c->issue($bad));reject(fn()=>$s->accept(str_repeat('b',64),$bad));
    }
    $bad=$intent;unset($bad['registry_contract_id']);reject(fn()=>$c->issue($bad));
    $bad=$intent;$bad['chain_id']='other-chain';reject(fn()=>$c->issue($bad));
    // Legacy numeric-ID operations cannot be rediscovered through the new store.
    $s->db->exec('CREATE TABLE operations(id TEXT, account TEXT, idem TEXT, intent TEXT)');
    $q=$s->db->prepare('INSERT INTO operations VALUES(?,?,?,?)');$q->execute([str_repeat('c',32),str_repeat('b',64),$intent['idempotency_key'],'{}']);
    if($s->byKey(str_repeat('b',64),$intent['idempotency_key'])!==null)throw new RuntimeException('Legacy operation rediscovered');
    $op=$s->accept(str_repeat('b',64),$intent);$again=$s->accept(str_repeat('b',64),$intent);
    if($op['id']!==$again['id']||json_decode($op['intent'],true)['registry_contract_id']!==DeploymentIdentity::REGISTRY)throw new RuntimeException('Scoped idempotency failed');
    echo "Registry identity: fresh app 1, signed registry domain, legacy/wrong/missing registry and chain rejection, isolated durable operations passed\n";
}finally{unset($s,$c,$q);foreach(glob($dir.'/*')as$p)unlink($p);rmdir($dir);}
