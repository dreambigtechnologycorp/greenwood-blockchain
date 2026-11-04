<?php
declare(strict_types=1);
require dirname(__DIR__,3).'/vendor/autoload.php';
use Greenwood\Contracts\{ContractId,ContractTransactionExecutor};
use Greenwood\State\State;
use Greenwood\Transaction\{Envelope,CallContract};
$wasm=file_get_contents($argv[1]);$id=ContractId::fromWasm($wasm)->bytes;
if(bin2hex($id)!==($argv[2]??'77e7bbde37f96369591a49fa63b5ce5b0d4e67c3bc98fd8c8fbabdff815d1715'))throw new RuntimeException('Wrong registry artifact');
$state=new State();$state->setContractCode($id,$wasm,$id);
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$out=[];$active=[];$apps=[];$storageHashes=[];
foreach($input as $row){
    $tx=Envelope::decode(hex2bin($row['canonical']));$tx->verify('greenwood-physical-v1');
    try {$state->applyTransaction($tx,'greenwood-physical-v1');$r=$state->getContractReceipt($tx->getHash());$out[]=$r->toArray();}
    catch(Throwable $e){$out[]=['rejected'=>true,'category'=>$e::class];}
    $records=[];foreach($state->getContractStorageRecords()as[$contract,$key,$value])if($contract===$id)$records[bin2hex($key)]=bin2hex($value);ksort($records);$storageHashes[]=hash('sha256',json_encode($records,JSON_THROW_ON_ERROR));
    $q=ContractTransactionExecutor::query($state,$id,'qac',pack('N',1));$active[]=bin2hex($q->overlay->effects()['returnValue']);$q=ContractTransactionExecutor::query($state,$id,'qap',pack('N',1));$apps[]=bin2hex($q->overlay->effects()['returnValue']);
}
$queries=[];foreach(['qap'=>pack('N',1),'qac'=>pack('N',1),'qrs'=>pack('NN',1,1)."web\0"] as $method=>$data){
 $r=ContractTransactionExecutor::query($state,$id,$method,$data);$queries[$method]=bin2hex($r->overlay->effects()['returnValue']);
}
echo json_encode(['receipts'=>$out,'queries'=>$queries,'active_after'=>$active,'app_after'=>$apps,'storage_hashes'=>$storageHashes],JSON_THROW_ON_ERROR);
