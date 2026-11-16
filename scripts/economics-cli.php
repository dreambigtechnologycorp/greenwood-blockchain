#!/usr/bin/env php
<?php
declare(strict_types=1);
// Source bootstrap also works before Composer installation; never substitute mock protocol classes.
require __DIR__.'/economics-bootstrap.php';
use Greenwood\Economics\{Amount,CanonicalJson,Policy};
use Greenwood\Node\Configuration;
use Greenwood\Crypto\{Ed25519KeyPair,ProtocolHash};
use Greenwood\Transaction\{Envelope,TransferU64};
try {
    $command=$argv[1]??'help';
    if($command==='prepare-production-genesis') {
        $read=static function(string $path):array { $bytes=file_get_contents($path);if(strlen($bytes)>262144)throw new RuntimeException('Bounded public input required');return json_decode($bytes,true,32,JSON_THROW_ON_ERROR); };
        $prepared=\Greenwood\Genesis\ProductionPreparation::prepare($read($argv[2]),$read($argv[3]));
        echo json_encode($prepared,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
        exit($prepared['status']==='BLOCKED'?2:0);
    } elseif($command==='validate-policy') {
        $p=Policy::validate(json_decode(file_get_contents($argv[2]),true,32,JSON_THROW_ON_ERROR));
        echo json_encode(['valid'=>true,'policy_hash'=>bin2hex(ProtocolHash::hash('GREENWOOD_ECONOMIC_POLICY_V1',CanonicalJson::encode($p))),'profile'=>$p['profile']],JSON_THROW_ON_ERROR)."\n";
    } elseif(in_array($command,['validate-genesis','validate-production-genesis'],true)) {
        [$g]=Configuration::load($argv[2]);$state=$g->initialState();if($state->economicSummary()===null)throw new RuntimeException('Native economic policy missing');
        if($command==='validate-production-genesis'){
            Policy::validateProduction($state->economicSummary()['policy']);
        }
        echo json_encode(['valid'=>true,'chain_id'=>$g->getChainId(),'genesis_hash'=>bin2hex($g->getGenesisHash()),'state_root'=>bin2hex($state->getStateRoot()),'issuance_base_units'=>(string)Amount::ISSUANCE],JSON_THROW_ON_ERROR)."\n";
    } elseif(in_array($command,['quote-sign','usage-sign','resource-usage-sign','resource-source-sign','resolution-sign'],true)){
        $key=Ed25519KeyPair::fromBytes(file_get_contents($argv[2]));$document=json_decode(file_get_contents($argv[3]),true,25,JSON_THROW_ON_ERROR);
        echo json_encode(\Greenwood\Economics\ServiceAuthorization::approval(substr($command,0,-5),$document,$key),JSON_THROW_ON_ERROR)."\n";
    } elseif(in_array($command,['treasury-sign','service-sign','transfer-sign','resource-capability-sign'],true)) {
        [$g]=Configuration::load($argv[2]);if($g->initialState()->economicSummary()===null)throw new RuntimeException('Native economics not enabled');
        $key=Ed25519KeyPair::fromBytes(file_get_contents($argv[3]));$sender=ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',$key->getPublicKey());
        if(in_array($command,['treasury-sign','service-sign','resource-capability-sign'],true)) {
            $bytes=file_get_contents($argv[4]);if(strlen($bytes)>65536)throw new RuntimeException('Payload too large');
            $action=json_decode($bytes,true,25,JSON_THROW_ON_ERROR);if($command==='treasury-sign' && (!is_int($action['policy_version']??null)||$action['policy_version']<1))throw new RuntimeException('Explicit current policy_version required');if(isset($action['genesis']) && $action['genesis']!==bin2hex($g->getGenesisHash()))throw new RuntimeException('Conflicting genesis');$action['genesis']=bin2hex($g->getGenesisHash());if($command==='service-sign')$action+=['version'=>1];$payload=CanonicalJson::encode($action);$nonce=Amount::integer($argv[5]);$type=match($command){'service-sign'=>Envelope::TYPE_SERVICE,'resource-capability-sign'=>Envelope::TYPE_PROVIDER_CAPABILITY_V2,default=>Envelope::TYPE_TREASURY};if($command==='resource-capability-sign'){\Greenwood\Resources\ProviderCapabilityV2::validate($action);if($action['provider_key']!==bin2hex($key->getPublicKey()))throw new RuntimeException('Resource capability signer mismatch');}
        } else {$payload=(new TransferU64(Configuration::hex($argv[4],32),Amount::parse($argv[5])))->serialize();$nonce=Amount::integer($argv[6]);$type=Envelope::TYPE_TRANSFER_U64;}
        $tx=new Envelope(1,$g->getChainId(),$sender,$nonce,$type,$payload,'',$key->getPublicKey());$tx->setSignature($key->sign($tx->getSigningDigest()));
        echo json_encode(['type'=>'transaction','payload'=>base64_encode($tx->getCanonicalBytes()),'tx_hash'=>bin2hex($tx->getHash())],JSON_THROW_ON_ERROR)."\n";
    } else echo "prepare-production-genesis POLICY_JSON PUBLIC_TOPOLOGY_JSON\nvalidate-policy POLICY_JSON\nvalidate-genesis GENESIS_JSON\nvalidate-production-genesis GENESIS_JSON\ntreasury-sign GENESIS KEY ACTION_JSON NONCE\nservice-sign GENESIS KEY ACTION_JSON NONCE\ntransfer-sign GENESIS KEY RECIPIENT GWD_DECIMAL NONCE\nresource-capability-sign GENESIS KEY CAPABILITY_JSON NONCE\nquote-sign KEY QUOTE_JSON\nusage-sign KEY EVIDENCE_JSON\nresource-usage-sign KEY EVIDENCE_JSON\nresolution-sign KEY RESOLUTION_JSON\nNo command generates keys, activates genesis, or broadcasts transactions.\n";
} catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
