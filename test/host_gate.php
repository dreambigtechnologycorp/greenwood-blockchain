<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../tests/Contracts/Support/ReadOnlyFixture.php';
use Greenwood\Contracts\{WasmtimeEngine,ContractExecutionContext};
use Greenwood\Tests\Contracts\Support\ReadOnlyFixture;
$name=$argv[1]??'input_size';
$r=(new WasmtimeEngine())->executeContract(file_get_contents(__DIR__.'/fixtures/host_'.$name.'.wasm'),new ContractExecutionContext(str_repeat('A',32),str_repeat('C',32),'hello',10,100000),new ReadOnlyFixture());
echo json_encode(['host'=>$name,'status'=>$r->runtime->getStatus(),'result'=>$r->runtime->getReturnValue(),'fuel'=>$r->runtime->getFuelConsumed(),'error'=>$r->runtime->getErrorMessage()],JSON_PRETTY_PRINT)."\n";
$expected=['input_size'=>5,'input_read'=>104,'caller'=>65,'contract_id'=>67,'storage_get'=>3,'storage_set'=>0,'storage_delete'=>0,'attached_value'=>10,'contract_balance'=>30,'transfer'=>0,'emit_event'=>0,'return_value'=>0];
if (!$r->runtime->isSuccess() || $r->runtime->getReturnValue()!==$expected[$name]) exit(1);
$effects=$r->overlay->effects();
$ok=match ($name) {
 'storage_set'=>($effects['storageWrites']["\0x"]??null)==='new',
 'storage_delete'=>isset($effects['storageDeletes']["\0x"]),
 'transfer'=>($effects['accountBalanceDeltas']["\0".str_repeat('B',32)]??null)===7 && ($effects['contractBalanceDeltas']["\0".str_repeat('C',32)]??null)===3,
 'emit_event'=>count($effects['events'])===1 && $effects['events'][0]->topic==='t' && $effects['events'][0]->data==='d',
 'return_value'=>$effects['returnValue']==='answer',
 default=>true,
};
exit($ok?0:1);
