<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Greenwood\Economics\{CanonicalJson,Policy};
use Greenwood\Node\{Chain,Configuration};
use Greenwood\Persistence\{BlockFilesystem,StateSqlite};
use Greenwood\Consensus\Messages\Certificate;
use Greenwood\Provider\{LocalResourceEvidence,QualifiedSourceAttestor,ResourceSettlementWorkflow,SettlementOutbox,SettlementCoordinator};

// One bounded invocation. A supervisor supplies reviewed immutable snapshot inputs.
// This interface cannot accept a caller-supplied ledger or turn a node assertion into replay proof.
umask(0077);
$index=null;
try {
    if($argc!==3)throw new RuntimeException('Usage: resource-workflow.php PRIVATE_DIRECTORY OPERATION');
    $directory=$argv[1];$operation=$argv[2];
    if(realpath($directory)!==$directory||!is_dir($directory)||fileowner($directory)!==posix_geteuid()||(fileperms($directory)&0077)!==0)throw new RuntimeException('Canonical private service directory required');
    $read=static function(string $path,int $maximum):string {
        if(is_link($path)||!is_file($path)||fileowner($path)!==posix_geteuid()||(fileperms($path)&0077)!==0||filesize($path)>$maximum)throw new RuntimeException('Private bounded input file required');
        return file_get_contents($path);
    };
    $config=json_decode($read($directory.'/config.json',32768),true,24,JSON_THROW_ON_ERROR);
    if(($config['enabled']??false)!==true){echo "{\"state\":\"DISABLED\"}\n";exit(0);}
    if(!in_array($operation,$config['allowed_operations']??[],true))throw new RuntimeException('Operation lacks explicit local consent');
    $chainId=$config['chain_id'];$genesisHash=Policy::hex($config['genesis_hash']);
    // Raw request includes no path overrides, private key data or network endpoints.
    $raw=stream_get_contents(STDIN,1048577);
    if(strlen($raw)>1048576)throw new RuntimeException('Bounded request required');
    $request=$raw===''?[]:CanonicalJson::decode(trim($raw));
    if($operation==='enqueue'){
        $bytes=base64_decode($request['payload']??'',true);
        if($bytes===false||base64_encode($bytes)!==($request['payload']??null))throw new RuntimeException('Canonical signed transaction required');
        $outbox=new SettlementOutbox($directory.'/outbox',$chainId,$genesisHash);
        $result=['tx_hash'=>$outbox->enqueue(\Greenwood\Transaction\Envelope::decode($bytes)),'broadcast'=>false];
    }else{
        Policy::hex($config['finalized_hash']??null);Policy::hex($config['state_root']??null);
        if(!is_int($config['finalized_height']??null)||$config['finalized_height']<1)throw new RuntimeException('Explicit finalized snapshot pin required');
        // Genesis must already exist; never let Chain synthesize an empty history here.
        $genesisFile=$directory.'/genesis.json';$read($genesisFile,262144);
        [$genesis]=Configuration::load($genesisFile);
        if($genesis->getChainId()!==$chainId||bin2hex($genesis->getGenesisHash())!==$genesisHash)throw new RuntimeException('Genesis pin mismatch');
        $blocks=$directory.'/blocks';
        if(realpath($blocks)!==$blocks||!is_dir($blocks)||!is_file($blocks.'/0000000000000000-block.bin'))throw new RuntimeException('Existing immutable certified history required');
        $files=new BlockFilesystem($blocks);
        if($files->getHighestHeight()!==$config['finalized_height'])throw new RuntimeException('Snapshot height mismatch');
        $indexPath=$directory.'/verified.sqlite';
        foreach(['','-wal','-shm'] as $suffix)if(is_link($indexPath.$suffix)||(file_exists($indexPath.$suffix)&&(!is_file($indexPath.$suffix)||fileowner($indexPath.$suffix)!==posix_geteuid()||(fileperms($indexPath.$suffix)&0077)!==0)))throw new RuntimeException('Unsafe derived index');
        $index=new StateSqlite($indexPath);
        $verify=static function($block,?string $certificate)use($genesis):void {
            if($certificate===null)throw new RuntimeException('Certificate missing');
            Certificate::decode($certificate)->verify($genesis->getValidators(),$genesis->getChainId(),$genesis->getGenesisHash(),'precommit',$block->getHeight(),$block->getHash());
        };
        $chain=new Chain($genesis,$files,$index,$verify);
        if($chain->tip->getHeight()!==$config['finalized_height']||bin2hex($chain->tip->getHash())!==$config['finalized_hash']||bin2hex($chain->state->getStateRoot())!==$config['state_root']||$files->getHighestHeight()!==$config['finalized_height'])throw new RuntimeException('Replayed snapshot pin mismatch');
        $id=$request['commitment']??null;$height=$chain->tip->getHeight()+1;
        $local=static function()use($chain,$id,$request,$chainId,$genesisHash):array {
            $economics=$chain->state->economicSummary();
            $commitment=$economics['services']['commitments'][$id]??throw new RuntimeException('Unknown funded commitment');
            if($commitment['status']!=='funded')throw new RuntimeException('Current funded commitment required');
            if($commitment['adapter']==='compute_resources_v2'){
                $assignment=$chain->state->getComputeState()->assignment(hex2bin($commitment['request_id']));
                if(!$assignment||$assignment->generation!==$commitment['generation'])throw new RuntimeException('Finalized matching assignment required');
                $commitment['usage_assignment_generation']=bin2hex(\Greenwood\Crypto\ProtocolHash::hash('GREENWOOD_RESOURCE_ASSIGNMENT_V1',$assignment->toBytes()));
            }
            return LocalResourceEvidence::prepare($request['bundle'],$commitment,$economics['services']['programs'][$commitment['program']]['policy'],$chainId,$genesisHash,$request['block_devices']??[]);
        };
        $result=match($operation){
            'prepare-local'=>$local(),
            'prepare-bootstrap'=>ResourceSettlementWorkflow::bootstrapUsageAction($chain->state,$id,$request['evidence'],$request['approvals'],$chainId,$genesis->getGenesisHash(),$height),
            'prepare-independent'=>ResourceSettlementWorkflow::usageAction($chain->state,$id,$request['evidence'],$request['approvals'],$chainId,$genesis->getGenesisHash(),$height),
            'attest-independent'=>['approval'=>QualifiedSourceAttestor::approve($chain->state,$id,$request['evidence'],$request['source_approval'],\Greenwood\Crypto\Ed25519KeyPair::fromBytes($read($directory.'/witness.key',128)),$chainId,$genesis->getGenesisHash(),$height)],
            'relay'=>SettlementCoordinator::forHttp(new SettlementOutbox($directory.'/outbox',$chainId,$genesisHash),$config['relay_origin'],$chainId)->tick($chain,time(),16),
            default=>throw new RuntimeException('Unsupported workflow operation'),
        };
    }
    echo json_encode(['status'=>'OK','operation'=>$operation,'result'=>$result,'payment_authority'=>false],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $error){fwrite(STDERR,json_encode(['status'=>'UNVERIFIED','error'=>$error->getMessage()],JSON_THROW_ON_ERROR)."\n");exit(1);}
finally{$index?->close();}
