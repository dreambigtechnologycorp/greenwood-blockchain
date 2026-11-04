<?php
declare(strict_types=1);
namespace Greenwood\Tests\Integration;
use PHPUnit\Framework\TestCase;
use Greenwood\Tests\Fixtures\ContractFixture as F;
use Greenwood\Codec\Canonical as C;
use Greenwood\Contracts\{ContractId,ContractTransactionException,ContractStateView,ContractStateCommitter,ContractExecutionContext,WasmtimeEngine};
use Greenwood\Transaction\{Envelope,DeployContract,CallContract,Transfer};
use Greenwood\State\State;
use Greenwood\Node\Chain;
use Greenwood\Genesis\Genesis;
use Greenwood\Block\Block;
use Greenwood\Persistence\{BlockFilesystem,StateSqlite};
use Greenwood\Consensus\Messages\{Message,Certificate};
final class ContractStateIntegrationTest extends TestCase
{
    private function id(string $name): string { return ContractId::fromWasm(F::wasm($name))->bytes; }
    private function reject(State $state,Envelope $tx,?string $status=null): void
    {
        $records=$state->logicalRecords(); $root=$state->getStateRoot(); $receipts=$state->getContractReceipts();
        try { $state->applyTransaction($tx,F::CHAIN); self::fail('Failed transaction accepted'); }
        catch (\InvalidArgumentException $e) {
            if ($status!==null) { self::assertInstanceOf(ContractTransactionException::class,$e); self::assertSame($status,$e->receipt->status); self::assertSame([],$e->receipt->events); self::assertSame('',$e->receipt->returnData); }
        }
        self::assertSame($records,$state->logicalRecords()); self::assertSame($root,$state->getStateRoot()); self::assertSame($receipts,$state->getContractReceipts());
    }
    public function testDeploymentInitDuplicateForbiddenAndRollback(): void
    {
        $s=F::state(); $tx=F::deploy($s,'valid',10,gas:0); $s->applyTransaction($tx,F::CHAIN);
        self::assertSame(F::wasm('valid'),$s->getContractCode($this->id('valid'))['wasm']); self::assertSame(10,$s->getContractBalance($this->id('valid'))); self::assertSame(0,$s->getContractReceipt($tx->getHash())->gasUsed);
        $this->reject($s,F::deploy($s,'valid',5),'CONTRACT_ALREADY_EXISTS');
        $tx=F::deploy($s,'init_storage',15,'constructed'); $s->applyTransaction($tx,F::CHAIN);
        self::assertSame('constructed',$s->getContractStorage($this->id('init_storage'),'init')); self::assertSame(15,$s->getContractBalance($this->id('init_storage')));
        foreach (['init_rollback_trap'=>'TRAP','init_rollback_fuel'=>'OUT_OF_GAS','init_rollback_memory'=>'EXECUTION_ERROR','wasi'=>'INVALID_WASM','float'=>'INVALID_WASM'] as $name=>$status) {
            $this->reject($s,F::deploy($s,$name,10,gas:100000),$status); self::assertNull($s->getContractCode($this->id($name)));
        }
        $bad=(new DeployContract('bad wasm','',0,100))->createEnvelope(F::CHAIN,F::address(),2,F::key()); $this->reject($s,$bad,'INVALID_WASM');
        self::assertSame(999975,$s->getAccount(F::address())->getBalance()); self::assertSame(2,$s->getAccount(F::address())->getNonce());
    }
    public function testStorageDispatchPersistenceEventsAndReturnData(): void
    {
        $s=F::state(); $s->applyTransaction(F::deploy($s,'reference_storage'),F::CHAIN); $id=$this->id('reference_storage');
        foreach (['0','00',"\0binary"] as $key) {
            $data=C::encodeBytes($key).C::encodeBytes('value'); $tx=F::call($s,'reference_storage','set',$data); $s->applyTransaction($tx,F::CHAIN);
            self::assertSame('value',$s->getContractStorage($id,$key)); $r=$s->getContractReceipt($tx->getHash());
            self::assertSame('SUCCESS',$r->status); self::assertCount(1,$r->events); self::assertSame($tx->getHash(),$r->events[0]->transactionHash); self::assertSame(0,$r->events[0]->eventIndex); self::assertSame('set',$r->events[0]->topic); self::assertSame($data,$r->events[0]->data);
            $tx=F::call($s,'reference_storage','get',C::encodeBytes($key)); $s->applyTransaction($tx,F::CHAIN); self::assertSame('value',$s->getContractReceipt($tx->getHash())->returnData);
            $tx=F::call($s,'reference_storage','delete',C::encodeBytes($key)); $s->applyTransaction($tx,F::CHAIN); self::assertNull($s->getContractStorage($id,$key)); self::assertSame('delete',$s->getContractReceipt($tx->getHash())->events[0]->topic);
        }
    }
    public function testNativeValueAndFailedCallsLeaveStateAndNonceUnchanged(): void
    {
        $s=F::state(); $s->applyTransaction(F::deploy($s,'reference_value',50),F::CHAIN);
        $tx=F::call($s,'reference_value','balance',value:20); $s->applyTransaction($tx,F::CHAIN); self::assertSame(C::encodeU64BE(70),$s->getContractReceipt($tx->getHash())->returnData);
        $tx=F::call($s,'reference_value','pay',F::address(5).C::encodeU64BE(7)); $s->applyTransaction($tx,F::CHAIN); self::assertSame(7,$s->getAccount(F::address(5))->getBalance()); self::assertSame(63,$s->getContractBalance($this->id('reference_value')));
        $this->reject($s,F::call($s,'reference_value','unknown',value:10),'TRAP');
        $this->reject($s,F::call($s,'reference_value','balance',value:10,gas:0),'OUT_OF_GAS');
        $this->reject($s,F::call($s,'reference_value','balance',value:PHP_INT_MAX),'INSUFFICIENT_BALANCE');
        foreach (['host_rollback_trap'=>'TRAP','host_rollback_fuel'=>'OUT_OF_GAS','host_rollback_memory'=>'EXECUTION_ERROR','wrong_signature'=>'EXECUTION_ERROR'] as $name=>$status) {
            $s->applyTransaction(F::deploy($s,$name),F::CHAIN); $this->reject($s,F::call($s,$name,'run',value:10),$status);
        }
        $this->reject($s,F::call($s,'valid','run'),'CONTRACT_NOT_FOUND');
    }
    public function testIndependentExecutionRootsAndAtomicCommitter(): void
    {
        $a=F::state(); $b=clone $a;
        foreach ([F::deploy($a,'reference_storage',10)] as $tx) { $a->applyTransaction($tx,F::CHAIN); $b->applyTransaction($tx,F::CHAIN); }
        $tx=F::call($a,'reference_storage','set',C::encodeBytes('x').C::encodeBytes('one'),5);
        $a->applyTransaction($tx,F::CHAIN); $b->applyTransaction($tx,F::CHAIN);
        self::assertSame($a->logicalRecords(),$b->logicalRecords()); self::assertSame($a->getStateRoot(),$b->getStateRoot()); self::assertEquals($a->getContractReceipts(),$b->getContractReceipts());
        $id=$this->id('reference_storage'); $b->setContractStorage($id,'x','two')->recomputeStateRoot(); self::assertNotSame($a->getStateRoot(),$b->getStateRoot());
        $b=clone $a; $b->setContractBalance($id,16)->recomputeStateRoot(); self::assertNotSame($a->getStateRoot(),$b->getStateRoot());
        $view=new ContractStateView($a); $a->setContractStorage($id,'x','changed'); self::assertSame('one',$view->storage($id,'x')); self::assertSame(0,$view->accountBalance(str_repeat('z',32))); self::assertFalse($a->hasAccount(str_repeat('z',32)));
        $s=F::state(); $s->applyTransaction(F::deploy($s,'host_effects'),F::CHAIN);
        $r=(new WasmtimeEngine())->executeContract(F::wasm('host_effects'),new ContractExecutionContext(F::address(),$this->id('host_effects'),'',10,100000),new ContractStateView($s));
        self::assertTrue($r->runtime->isSuccess()); $s->setAccountBalance(F::address(),0)->recomputeStateRoot(); $before=$s->logicalRecords();
        try { ContractStateCommitter::apply($s,$r->overlay); self::fail(); } catch (\InvalidArgumentException) { self::assertSame($before,$s->logicalRecords()); }
    }
    public function testAuthorizationNonceAndMalformedPayloadRejectBeforeMutation(): void
    {
        $s=F::state(); $tx=F::deploy($s,'valid'); $tx->setSignature(str_repeat('x',64)); $this->reject($s,$tx);
        $tx=(new DeployContract(F::wasm('valid'),'',0,100))->createEnvelope('wrong',F::address(),0,F::key()); $this->reject($s,$tx);
        $tx=(new DeployContract(F::wasm('valid'),'',0,100))->createEnvelope(F::CHAIN,F::address(),1,F::key()); $this->reject($s,$tx);
        foreach ([2,3] as $type) {
            $tx=new Envelope(1,F::CHAIN,F::address(),0,$type,'bad','',F::key()->getPublicKey()); $tx->setSignature(F::key()->sign($tx->getSigningDigest())); $this->reject($s,$tx);
        }
        $s->setAccountNonce(F::address(),0xffffffff)->recomputeStateRoot(); $this->reject($s,F::deploy($s,'valid'));
    }
    public function testFinalizedSingleProcessReplayAndSqliteDestruction(): void
    {
        $dir=sys_get_temp_dir().'/greenwood-contract-replay-'.bin2hex(random_bytes(6)); $g=new Genesis(F::CHAIN); $keys=[];
        foreach (['A','B','C','D'] as $i=>$id) { $keys[$id]=F::key($i); $g->addValidator($id,$keys[$id]); }
        $g->addInitialAccount('sender',F::key(),1000000); $files=new BlockFilesystem($dir.'/blocks'); $db=new StateSqlite($dir.'/state.sqlite');
        $verify=static function(Block $block,?string $bytes) use ($g): void {
            Certificate::decode($bytes??'')->verify($g->getValidators(),F::CHAIN,$g->getGenesisHash(),'precommit',$block->getHeight(),$block->getHash());
        };
        $chain=new Chain($g,$files,$db,$verify);
        $steps=[
            fn(State $s)=>(new Transfer(F::address(5),25))->createEnvelope(F::CHAIN,F::address(),0,F::key()),
            fn(State $s)=>F::deploy($s,'reference_storage',10),
            fn(State $s)=>F::call($s,'reference_storage','set',C::encodeBytes('x').C::encodeBytes('durable')),
            fn(State $s)=>F::call($s,'reference_storage','get',C::encodeBytes('x'),5),
            fn(State $s)=>F::deploy($s,'reference_value',20),
            fn(State $s)=>F::call($s,'reference_value','pay',F::address(5).C::encodeU64BE(7)),
        ];
        foreach ($steps as $make) {
            $tx=Envelope::decode($make($chain->state)->getCanonicalBytes()); $h=$chain->tip->getHeight()+1; $old=$chain->state->logicalRecords();
            $block=$chain->build([$tx],0,F::address($h%4)); $block=Block::fromBytes($block->toBytes()); self::assertSame($old,$chain->state->logicalRecords());
            $independent=$chain->execute($block); self::assertSame($block->getStateRoot(),$independent->getStateRoot());
            self::assertSame($old,$chain->state->logicalRecords());
            $header=$block->getHeader();
            $bad=new Block(new \Greenwood\Block\Header(1,F::CHAIN,$h,0,$header->getParentHash(),$header->getPreviousStateRoot(),$header->getTransactionsRoot(),str_repeat('x',32),F::address($h%4),$g->getGenesisHash(),0),[$tx]);
            try { $chain->execute($bad); self::fail('Invalid candidate root accepted'); } catch (\InvalidArgumentException) { self::assertSame($old,$chain->state->logicalRecords()); }

            $votes=[]; foreach (['A','B','C'] as $id) { $m=new Message('precommit',F::CHAIN,$g->getGenesisHash(),$h,0,$id,$block->getHash()); $votes[]=$m->signed($keys[$id]->sign($m->digest())); }
            $chain->finalize($block,(new Certificate($votes))->bytes()); self::assertSame($chain->state->getStateRoot(),$db->computeStateRoot());
        }
        $records=$chain->state->logicalRecords(); $receipts=array_map(fn($r)=>$r->toArray(),$chain->state->getContractReceipts()); $root=$chain->state->getStateRoot(); $hash=$chain->tip->getHash(); $codes=$chain->state->getContractCodes();
        $db->close(); unset($chain,$db);
        foreach ([false,true] as $delete) {
            if ($delete) unlink($dir.'/state.sqlite');
            $db=new StateSqlite($dir.'/state.sqlite'); $chain=new Chain($g,$files,$db,$verify);
            self::assertSame(6,$chain->tip->getHeight()); self::assertSame($hash,$chain->tip->getHash()); self::assertSame($root,$chain->state->getStateRoot()); self::assertSame($root,$db->computeStateRoot());
            self::assertSame($records,$chain->state->logicalRecords()); self::assertSame($records,$db->loadState()->logicalRecords()); self::assertSame($codes,$chain->state->getContractCodes());
            self::assertSame($receipts,array_map(fn($r)=>$r->toArray(),$chain->state->getContractReceipts())); self::assertSame($receipts,array_map(fn($r)=>$r->toArray(),$db->loadState()->getContractReceipts()));
            $db->close(); unset($chain,$db);
        }
        file_put_contents($dir.'/acceptance.json',json_encode(['height'=>6,'hash'=>bin2hex($hash),'root'=>bin2hex($root),'receipts'=>array_values($receipts),'sqlite_rebuilt'=>true],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        echo "\nContract replay artifacts: $dir\n";
    }
    public function testMissingCallWrongInitAndCodeImmutability(): void
    {
        $s=F::state();
        $empty=hex2bin('0061736d01000000'); $id=ContractId::fromWasm($empty)->bytes;
        $tx=(new DeployContract($empty,'',0,0))->createEnvelope(F::CHAIN,F::address(),0,F::key()); $s->applyTransaction($tx,F::CHAIN);
        self::assertSame(0,$s->getContractBalance($id));
        $call=(new CallContract($id,'run','',0,100))->createEnvelope(F::CHAIN,F::address(),1,F::key()); $this->reject($s,$call,'MISSING_EXPORT');
        require_once __DIR__.'/../Contracts/Support/WatCompiler.php';
        foreach (['(module (memory (export "greenwood_init") 1 1))','(module (func (export "greenwood_init") (param i32) (result i32) local.get 0))'] as $wat) {
            $wasm=\Greenwood\Tests\Contracts\Support\WatCompiler::compile($wat);
            $tx=(new DeployContract($wasm,'',10,100))->createEnvelope(F::CHAIN,F::address(),1,F::key()); $this->reject($s,$tx,'EXECUTION_ERROR');
        }
        $before=$s->logicalRecords();
        try { $s->setContractCode($id,$empty,$id); self::fail(); } catch (\InvalidArgumentException) { self::assertSame($before,$s->logicalRecords()); }
        $s->setContractStorage($id,'x',''); $s->recomputeStateRoot();
        $clone=clone $s; $clone->deleteContractStorage($id,'x')->recomputeStateRoot(); self::assertNotSame($s->getStateRoot(),$clone->getStateRoot(),'Empty and absent storage differ');
    }
    public function testFailedOverlayCannotCommitAndTransferToCaller(): void
    {
        $s=F::state(); $s->applyTransaction(F::deploy($s,'host_rollback_trap'),F::CHAIN);
        $r=(new WasmtimeEngine())->executeContract(F::wasm('host_rollback_trap'),new ContractExecutionContext(F::address(),$this->id('host_rollback_trap'),'',10,100000),new ContractStateView($s));
        self::assertFalse($r->overlay->isSealed()); $before=$s->logicalRecords();
        try { ContractStateCommitter::apply($s,$r->overlay); self::fail(); } catch (\InvalidArgumentException) { self::assertSame($before,$s->logicalRecords()); }
        $s->applyTransaction(F::deploy($s,'reference_value',30),F::CHAIN);
        $s->applyTransaction(F::call($s,'reference_value','pay',F::address().C::encodeU64BE(7),5),F::CHAIN);
        self::assertSame(999972,$s->getAccount(F::address())->getBalance()); self::assertSame(28,$s->getContractBalance($this->id('reference_value')));
    }
    public function testSqliteContractCommitIsAtomic(): void
    {
        $dir=sys_get_temp_dir().'/greenwood-contract-atomic-'.bin2hex(random_bytes(5));
        $g=new Genesis(F::CHAIN); foreach (['A','B','C','D'] as $i=>$id) $g->addValidator($id,F::key($i)); $g->addInitialAccount('sender',F::key(),1000000);
        $db=new StateSqlite($dir.'/state.sqlite'); $chain=new Chain($g,new BlockFilesystem($dir.'/blocks'),$db);
        $block=$chain->build([F::deploy($chain->state,'reference_storage',10)],0,F::address(1)); $chain->finalize($block);
        $old=$db->loadState()->logicalRecords(); $tipHash=$db->getFinalizedBlockHash();
        $next=$chain->execute($chain->build([F::call($chain->state,'reference_storage','set',C::encodeBytes('x').C::encodeBytes('y'))],0,F::address(2)));
        $pdo=new \PDO('sqlite:'.$dir.'/state.sqlite'); $pdo->exec("CREATE TRIGGER fail_contract_write BEFORE INSERT ON contract_storage BEGIN SELECT RAISE(ABORT, 'injected index failure'); END");
        try { $db->commitState($next,$block,$g->getValidators()); self::fail(); }
        catch (\PDOException) { self::assertSame($old,$db->loadState()->logicalRecords()); self::assertSame($tipHash,$db->getFinalizedBlockHash()); }
        $db->close();
    }
    public function testMissingExportReceiptIncludesInstantiationFuel(): void
    {
        require_once __DIR__.'/../Contracts/Support/WatCompiler.php';
        $wasm=\Greenwood\Tests\Contracts\Support\WatCompiler::compile('(module (memory (export "memory") 1 1) (data (i32.const 0) "x"))');
        $s=F::state(); $id=ContractId::fromWasm($wasm)->bytes;
        $deploy=(new DeployContract($wasm,'',0,100000))->createEnvelope(F::CHAIN,F::address(),0,F::key());
        $s->applyTransaction($deploy,F::CHAIN); $gas=$s->getContractReceipt($deploy->getHash())->gasUsed; self::assertGreaterThan(0,$gas);
        $before=$s->logicalRecords(); $call=(new CallContract($id,'run','',0,100000))->createEnvelope(F::CHAIN,F::address(),1,F::key());
        try { $s->applyTransaction($call,F::CHAIN); self::fail(); }
        catch (ContractTransactionException $e) { self::assertSame('MISSING_EXPORT',$e->receipt->status); self::assertSame($gas,$e->receipt->gasUsed); self::assertSame($before,$s->logicalRecords()); }
        self::assertNull((new WasmtimeEngine())->execute($wasm,'absent',100000)->getFuelConsumed(),'Raw 3A behavior remains unchanged');
    }
}
