#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__.'/economics-bootstrap.php';
use Greenwood\Resources\ResourceCatalog;
use Greenwood\Economics\CanonicalJson;
try{
 $command=$argv[1]??'help';
 $read=static function(string $path):array{$raw=file_get_contents($path,false,null,0,65537);if($raw===false||strlen($raw)>65536)throw new RuntimeException('Bounded input required');return CanonicalJson::decode($raw);};
 $enqueue=static function(string $dir,string $chain,string $genesis,string $file):array{
  $raw=file_get_contents($file,false,null,0,131073);if($raw===false||strlen($raw)>131072)throw new RuntimeException('Bounded signed envelope required');$json=json_decode($raw,true,24,JSON_THROW_ON_ERROR);$frame=$json['body']??$json;
  if(($frame['type']??null)!=='transaction'||!is_string($frame['payload']??null))throw new RuntimeException('Signed transaction frame required');$bytes=base64_decode($frame['payload'],true);if($bytes===false||base64_encode($bytes)!==$frame['payload'])throw new RuntimeException('Canonical transaction base64 required');
  $tx=\Greenwood\Transaction\Envelope::decode($bytes);$id=(new \Greenwood\Provider\SettlementOutbox($dir,$chain,$genesis))->enqueue($tx);return ['tx_hash'=>$id,'state'=>'PENDING','broadcast'=>false];
 };
 $result=match($command){
  'catalog'=>['definitions'=>ResourceCatalog::definitions(),'payable_defaults'=>ResourceCatalog::disabled()],
  'validate-network-policy'=>['record'=>\Greenwood\Network\ResourcePolicyState::validate($read($argv[2])),'ratified'=>false],
  'cost-projection'=>\Greenwood\Network\InfrastructureCostModel::project($read($argv[2]),\Greenwood\Economics\Amount::integer($argv[3]),\Greenwood\Economics\Amount::integer($argv[4]),\Greenwood\Economics\Amount::integer($argv[5])),
  'outbox-status'=>(new \Greenwood\Provider\SettlementOutbox($argv[2],$argv[3],$argv[4]))->status($argv[5]),
  'outbox-enqueue'=>$enqueue($argv[2],$argv[3],$argv[4],$argv[5]),
  default=>['commands'=>['catalog','validate-network-policy CANONICAL_JSON','cost-projection CANONICAL_JSON QUALIFIED_BLOCKS ATTESTATIONS OPERATORS','outbox-status PRIVATE_DIRECTORY CHAIN GENESIS TX_HASH','outbox-enqueue PRIVATE_DIRECTORY CHAIN GENESIS SIGNED_TRANSACTION_JSON'],'signing'=>'Use existing economics-cli.php; no key generation or broadcasting here.'],
 };
 echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
