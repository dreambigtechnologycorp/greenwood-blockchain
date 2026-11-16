#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__.'/economics-bootstrap.php';
use Greenwood\Provider\{MeteringService,ResourceTelemetryJournal,ServiceOperationMeter,SettlementOutbox,SystemServiceInstallJournal};
use Greenwood\Resources\Metering\{LinuxCollectors,AllocationAccounting,TelemetryFrame};
use Greenwood\Economics\CanonicalJson;
umask(0077);
try{
 $read=static function(string $file):array{$raw=file_get_contents($file,false,null,0,1048577);if($raw===false||strlen($raw)>1048576)throw new RuntimeException('Bounded JSON input required');$body=json_decode($raw,true,25,JSON_THROW_ON_ERROR);if(!is_array($body))throw new RuntimeException('JSON object/list required');CanonicalJson::encode($body);return $body;};
 $command=$argv[1]??'help';
 $result=match($command){
  'inventory'=>LinuxCollectors::inventory(),
  'sample-task'=>(function()use($argv,$read){$sample=MeteringService::observeTask($argv[2],$argv[3]);return ['sample'=>$sample,'measurements'=>LinuxCollectors::measurements($read($argv[4]),$sample,$read($argv[5])),'payable'=>false];})(),
  'sample-network'=>\Greenwood\Resources\Metering\NetworkAccounting::observe($read($argv[2]),$read($argv[3])),
  'sample-allocation'=>['measurements'=>AllocationAccounting::measurements($read($argv[2]),$read($argv[3]),$read($argv[4]),$read($argv[5]),\Greenwood\Economics\Amount::integer($argv[6])),'payable'=>false],
  'sign-frame'=>(function()use($argv,$read){$path=$argv[2];if(is_link($path)||!is_file($path)||fileowner($path)!==posix_geteuid()||(fileperms($path)&0077)!==0||filesize($path)!==96)throw new RuntimeException('Explicit private source key required');$key=\Greenwood\Crypto\Ed25519KeyPair::fromBytes(file_get_contents($path));return TelemetryFrame::sign($read($argv[3]),$key);})(),
  'telemetry-bind'=>(function()use($argv,$read){(new ResourceTelemetryJournal($argv[2]))->bind($read($argv[3]),$read($argv[4]),$argv[5],\Greenwood\Economics\Amount::integer($argv[6]));return ['bound'=>true,'payable'=>false];})(),
  'telemetry-append','telemetry-close'=>(new ResourceTelemetryJournal($argv[2]))->append($argv[3],$read($argv[4]),$command==='telemetry-close'),
  'telemetry-evidence'=>['evidence'=>(new ResourceTelemetryJournal($argv[2]))->evidence($argv[3]),'payable'=>false,'verification_level'=>'C','signing_required'=>true],
  'telemetry-archive'=>['archive_hash'=>(new ResourceTelemetryJournal($argv[2]))->archiveClosed($argv[3],$argv[4])],
  'meter-archive'=>['archive_hash'=>(new MeteringService($argv[2]))->archiveClosed($argv[3],$argv[4])],
  'outbox-archive'=>['archive_hash'=>(new SettlementOutbox($argv[2],$argv[3],$argv[4]))->archiveTerminal($argv[5],$argv[6])],
  'installation-archive'=>['archive_hash'=>(new SystemServiceInstallJournal($argv[2],$argv[3],$argv[4]))->archiveStopped($argv[5],$argv[6])],
  'operation-begin'=>['state'=>(new ServiceOperationMeter($argv[2],$read($argv[3])))->begin($argv[4])],
  'operation-complete'=>['receipt_hash'=>(new ServiceOperationMeter($argv[2],$read($argv[3])))->complete($argv[4],$read($argv[5]))],
  'operation-status'=>(new ServiceOperationMeter($argv[2],$read($argv[3])))->status($argv[4]),
  'operation-snapshot'=>(new ServiceOperationMeter($argv[2],$read($argv[3])))->snapshot($read($argv[4])),
  default=>['commands'=>['inventory','sample-task NAMESPACE CONTAINER MANIFEST_JSON BLOCK_BINDINGS_JSON','sample-network BINDING_JSON RUNTIME_JSON','sample-allocation MANIFEST_JSON SCOPE_JSON LEASES_JSON RUNTIME_JSON MONOTONIC_MS','sign-frame PRIVATE_SOURCE_KEY FRAME_JSON','telemetry-bind PRIVATE_DIR SCOPE_JSON MANIFEST_JSON SOURCE_PUBLIC_KEY MAXIMUM_GAP_MS','telemetry-append PRIVATE_DIR COMMITMENT SIGNED_FRAME_JSON','telemetry-close PRIVATE_DIR COMMITMENT SIGNED_FRAME_JSON','telemetry-evidence PRIVATE_DIR COMMITMENT','telemetry-archive PRIVATE_DIR COMMITMENT HEAD','meter-archive PRIVATE_DIR COMMITMENT BUNDLE_HASH','outbox-archive PRIVATE_DIR CHAIN GENESIS TX_HASH FINALIZED_OR_NONCE_CONFLICT','installation-archive PRIVATE_DIR CHAIN GENESIS POLICY_HASH AUDIT_HEAD','operation-status PRIVATE_DIR SCOPE_JSON EVENT_ID','operation-begin PRIVATE_DIR SCOPE_JSON EVENT_ID','operation-complete PRIVATE_DIR SCOPE_JSON EVENT_ID QUANTITIES_JSON','operation-snapshot PRIVATE_DIR SCOPE_JSON MANIFEST_JSON'],'activation'=>false,'broadcast'=>false],
 };
 echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
