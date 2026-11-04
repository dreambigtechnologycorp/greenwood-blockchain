<?php
declare(strict_types=1);
namespace Greenwood\Tests\Contracts;
require_once __DIR__.'/Support/ReadOnlyFixture.php';
require_once __DIR__.'/Support/WatCompiler.php';
use Greenwood\Contracts\{WasmtimeEngine,ContractExecutionContext,ContractExecutionResult,ContractStateViewInterface,ContractLimits,ContractAbi,WasmValidationException,WasmtimeHostBindings,ContractStatus};
use Greenwood\Tests\Contracts\Support\{ReadOnlyFixture,WatCompiler};
use PHPUnit\Framework\TestCase;
final class ContractHostIntegrationTest extends TestCase
{
    private function context(int $fuel=100000,int $attached=10): ContractExecutionContext
    { return new ContractExecutionContext(str_repeat('A',32),str_repeat('C',32),'hello',$attached,$fuel); }
    private function fixture(string $name): string { return file_get_contents(__DIR__.'/../../test/fixtures/host_'.$name.'.wasm'); }
    private function runWasm(string $wasm,?ContractStateViewInterface $state=null,int $fuel=100000,int $attached=10): ContractExecutionResult
    { return (new WasmtimeEngine())->executeContract($wasm,$this->context($fuel,$attached),$state??new ReadOnlyFixture()); }
    private function wat(string $imports,string $body,string $extra=''): string
    { return WatCompiler::compile('(module '.$imports.' (memory (export "memory") 2 3) '.$extra.' (func (export "greenwood_call") (result i32) '.$body.'))'); }
    private function import(string $name,string $local='host'): string
    {
        [$params,$result]=ContractAbi::FUNCTIONS[$name];
        $types=array_map(static fn(int $v)=>$v===0?'i32':'i64',$params);
        return '(import "greenwood" "'.$name.'" (func $'.$local.($types===[]?'':' (param '.implode(' ',$types).')').' (result '.($result===0?'i32':'i64').')))';
    }
    private function assertDiscarded(ContractExecutionResult $r): void
    {
        self::assertFalse($r->runtime->isSuccess());
        self::assertSame(['storageWrites'=>[],'storageDeletes'=>[],'accountBalanceDeltas'=>[],'contractBalanceDeltas'=>[],'events'=>[],'returnValue'=>''],$r->overlay->effects());
    }
    public function testAllTwelveIndividualNativeFixtures(): void
    {
        $expected=['input_size'=>5,'input_read'=>104,'caller'=>65,'contract_id'=>67,'storage_get'=>3,'storage_set'=>0,'storage_delete'=>0,'attached_value'=>10,'contract_balance'=>30,'transfer'=>0,'emit_event'=>0,'return_value'=>0];
        self::assertSame(array_keys(ContractAbi::FUNCTIONS),array_keys($expected));
        foreach ($expected as $name=>$value) {
            $r=$this->runWasm($this->fixture($name));
            self::assertSame('success',$r->runtime->getStatus(),$name.': '.$r->runtime->getErrorMessage());
            self::assertSame($value,$r->runtime->getReturnValue(),$name);
        }
        self::assertSame(["\0x"=>'new'],$this->runWasm($this->fixture('storage_set'))->overlay->effects()['storageWrites']);
        self::assertSame(["\0x"=>true],$this->runWasm($this->fixture('storage_delete'))->overlay->effects()['storageDeletes']);
    }
    public function testIdentityAndInputAreExactRawBytes(): void
    {
        foreach (['caller'=>str_repeat('A',32),'contract_id'=>str_repeat('C',32),'input_read'=>'hello'] as $name=>$expected) {
            $imports=$this->import($name).$this->import('return_value','ret');
            $body='i32.const 0 '.($name==='input_read'?'i32.const 5 ':'').'call $host drop i32.const 0 i32.const '.strlen($expected).' call $ret';
            $r=$this->runWasm($this->wat($imports,$body));
            self::assertSame('success',$r->runtime->getStatus()); self::assertSame($expected,$r->overlay->effects()['returnValue']);
        }
    }
    public function testStoragePrecedenceFoundEmptyMissingAndSmallBuffer(): void
    {
        $imports=$this->import('storage_get','get').$this->import('storage_set','set').$this->import('storage_delete','del').$this->import('return_value','ret');
        $set='i32.const 0 i32.const 1 i32.const 1 i32.const 3 call $set drop ';
        $del='i32.const 0 i32.const 1 call $del drop ';
        $get='i32.const 0 i32.const 1 i32.const 16 i32.const 3 call $get';
        foreach ([[$set,3,'new'],[$set.$del,-1,''],[$del.$set,3,'new'],['',3,'old']] as [$prefix,$expected,$bytes]) {
            $body=$prefix.$get.' i32.const '.$expected.' i32.ne if unreachable end i32.const 16 i32.const '.strlen($bytes).' call $ret';
            $r=$this->runWasm($this->wat($imports,$body,'(data (i32.const 0) "xnew")'));
            self::assertSame('success',$r->runtime->getStatus(),$r->runtime->getErrorMessage()??''); self::assertSame($bytes,$r->overlay->effects()['returnValue']);
        }
        $r=$this->runWasm($this->wat($imports,'i32.const 0 i32.const 1 i32.const 1 i32.const 0 call $set drop '.$get,'(data (i32.const 0) "x")'));
        self::assertSame(0,$r->runtime->getReturnValue());
        $r=$this->runWasm($this->wat($imports,'i32.const 0 i32.const 1 i32.const 16 i32.const 2 call $get','(data (i32.const 0) "x")'));
        self::assertSame(-2,$r->runtime->getReturnValue());
        $r=$this->runWasm($this->wat($imports,$get,'(data (i32.const 0) "z")'));
        self::assertSame(-1,$r->runtime->getReturnValue());
    }
    public function testTrapFuelMemoryAndBackendFailuresRollback(): void
    {
        $engine=new WasmtimeEngine(); $state=new ReadOnlyFixture();
        $before=[$state->accountBalance(str_repeat('A',32)),$state->contractBalance(str_repeat('C',32)),$state->storage(str_repeat('C',32),'x')];
        foreach (['rollback_trap'=>'trap','rollback_fuel'=>'fuel_exhausted','rollback_memory'=>'error'] as $fixture=>$status) {
            $r=$engine->executeContract($this->fixture($fixture),$this->context(),$state);
            self::assertSame($status,$r->runtime->getStatus(),$r->runtime->getErrorMessage()??''); $this->assertDiscarded($r);
            if ($status==='fuel_exhausted') self::assertSame(100000,$r->runtime->getFuelConsumed());
            self::assertSame(42,$engine->executeContract($this->fixture('effects'),$this->context(),$state)->runtime->getReturnValue());
        }
        // Same effects prefix, then a backend read: distinguish infrastructure from NOT_FOUND.
        $wat=file_get_contents(__DIR__.'/../../test/fixtures/host_effects.wat');
        $wat=str_replace('(memory (export',$this->import('storage_get','get').' (memory (export',$wat);
        $wat=str_replace('i32.const 42','i32.const 80 i32.const 1 i32.const 90 i32.const 1 call $get',$wat);
        $r=$this->runWasm(WatCompiler::compile($wat),new ReadOnlyFixture(fail:true));
        $this->assertDiscarded($r); self::assertSame('error',$r->runtime->getStatus()); self::assertStringContainsString('backend failure',$r->runtime->getErrorMessage());
        self::assertSame($before,[$state->accountBalance(str_repeat('A',32)),$state->contractBalance(str_repeat('C',32)),$state->storage(str_repeat('C',32),'x')]);
    }
    public function testDeterminism100RunsAndSealedResults(): void
    {
        $engine=new WasmtimeEngine(); $state=new ReadOnlyFixture(); $wasm=$this->fixture('effects');
        $first=$engine->executeContract($wasm,$this->context(),$state);
        self::assertSame('success',$first->runtime->getStatus());
        $effects=$first->overlay->effects();
        self::assertSame(["\0x"=>'new'],$effects['storageWrites']);
        self::assertSame(["\0".str_repeat('A',32)=>-10,"\0".str_repeat('B',32)=>7],$effects['accountBalanceDeltas']);
        self::assertSame(["\0".str_repeat('C',32)=>3],$effects['contractBalanceDeltas']);
        self::assertSame('answer',$effects['returnValue']);
        self::assertSame([str_repeat('C',32),'t','d',0],array_values(get_object_vars($effects['events'][0])));
        for ($i=0;$i<100;$i++) {
            $r=$engine->executeContract($wasm,$this->context(),$state);
            self::assertSame(['success',42,$first->runtime->getFuelConsumed()],[$r->runtime->getStatus(),$r->runtime->getReturnValue(),$r->runtime->getFuelConsumed()]);
            self::assertEquals($effects,$r->overlay->effects());
        }
        $this->expectException(\LogicException::class); $first->overlay->storageSet('x','overwrite');
    }
    public function testMemoryAdversarialCasesAndRecovery(): void
    {
        $cases=[
            ['return_value','i32.const -1 i32.const 1 call $host'],
            ['return_value','i32.const 131071 i32.const 2 call $host'],
            ['input_read','i32.const 2147483647 i32.const 2147483647 call $host'],
            ['input_read','i32.const 131072 i32.const 1 call $host'],
            ['input_read','i32.const 0 i32.const -1 call $host'],
            ['caller','i32.const 131060 call $host'],
            ['transfer','i32.const 131060 i64.const 1 call $host'],
            ['storage_get','i32.const 0 i32.const 1 i32.const 131072 i32.const 1 call $host'],
            ['storage_set','i32.const 0 i32.const 1 i32.const 131072 i32.const 1 call $host'],
        ];
        foreach ($cases as [$name,$body]) {
            $wasm=$this->wat($this->import($name),$body); $a=$this->runWasm($wasm); $b=$this->runWasm($wasm);
            self::assertSame('error',$a->runtime->getStatus(),$name); $this->assertDiscarded($a);
            self::assertSame([$a->runtime->getStatus(),$a->runtime->getFuelConsumed(),$a->runtime->getErrorMessage()],[$b->runtime->getStatus(),$b->runtime->getFuelConsumed(),$b->runtime->getErrorMessage()]);
            self::assertSame(5,$this->runWasm($this->fixture('input_size'))->runtime->getReturnValue());
        }
        // Zero bytes at end are valid; a pointer past end is still invalid.
        foreach (['input_read','return_value'] as $name) {
            self::assertSame(0,$this->runWasm($this->wat($this->import($name),'i32.const 131072 i32.const 0 call $host'))->runtime->getReturnValue());
            $this->assertDiscarded($this->runWasm($this->wat($this->import($name),'i32.const 131073 i32.const 0 call $host')));
        }
        $wasm=WatCompiler::compile('(module '.$this->import('input_read').' (func (export "greenwood_call") (result i32) i32.const 0 i32.const 0 call $host))');
        $this->assertDiscarded($this->runWasm($wasm));
        // A memory.grow invalidates old pointers; the next host call must find new memory.
        $body='i32.const 1 memory.grow drop i32.const 131072 i32.const 5 call $host drop i32.const 131072 i32.load8_u';
        self::assertSame(104,$this->runWasm($this->wat($this->import('input_read'),$body))->runtime->getReturnValue());
    }
    public function testRecoverableErrorsAndLimits(): void
    {
        foreach ([
            ['storage_get','i32.const 0 i32.const 0 i32.const 16 i32.const 1 call $host'],
            ['storage_set','i32.const 0 i32.const 257 i32.const 500 i32.const 1 call $host'],
            ['storage_set','i32.const 0 i32.const 1 i32.const 1 i32.const 65537 call $host'],
            ['return_value','i32.const 0 i32.const 65537 call $host'],
            ['emit_event','i32.const 0 i32.const 257 i32.const 500 i32.const 1 call $host'],
            ['emit_event','i32.const 0 i32.const 1 i32.const 500 i32.const 4097 call $host'],
            ['input_read','i32.const 0 i32.const 6 call $host'],
            ['transfer','i32.const 0 i64.const -1 call $host'],
            ['transfer','i32.const 0 i64.const 0 call $host'],
        ] as [$name,$body]) {
            $r=$this->runWasm($this->wat($this->import($name),$body));
            self::assertSame('success',$r->runtime->getStatus(),$r->runtime->getErrorMessage()??''); self::assertSame(-3,$r->runtime->getReturnValue());
            self::assertSame([], $r->overlay->effects()['storageWrites']);
        }
        self::assertSame(-4,$this->runWasm($this->wat($this->import('transfer'),'i32.const 0 i64.const 31 call $host'))->runtime->getReturnValue());
        self::assertSame(-5,$this->runWasm($this->fixture('transfer'),new ReadOnlyFixture(PHP_INT_MAX,20),attached:0)->runtime->getReturnValue());
        foreach (['emit_event','transfer'] as $name) {
            $one=$name==='emit_event'?'i32.const 0 i32.const 0 i32.const 0 i32.const 0 call $host':'i32.const 0 i64.const 1 call $host';
            $body=str_repeat($one.' drop ',64).$one;
            $r=$this->runWasm($this->wat($this->import($name),$body),new ReadOnlyFixture(100,100));
            self::assertSame('success',$r->runtime->getStatus()); self::assertSame(-6,$r->runtime->getReturnValue());
        }
        // last return wins, including an empty replacement.
        $body='i32.const 0 i32.const 3 call $host drop i32.const 0 i32.const 0 call $host';
        self::assertSame('',$this->runWasm($this->wat($this->import('return_value'),$body,'(data (i32.const 0) "abc")'))->overlay->effects()['returnValue']);
    }
    public function testAttachedValueFailuresAndSignedI64Boundary(): void
    {
        foreach ([new ReadOnlyFixture(9,20),new ReadOnlyFixture(100,PHP_INT_MAX)] as $state) $this->assertDiscarded($this->runWasm($this->fixture('input_size'),$state));
        $r=$this->runWasm($this->fixture('attached_value'),new ReadOnlyFixture(PHP_INT_MAX,0),attached:PHP_INT_MAX);
        self::assertSame(PHP_INT_MAX,$r->runtime->getReturnValue()); self::assertSame(PHP_INT_MAX,$r->overlay->contractBalance());
        $this->expectException(\InvalidArgumentException::class); $this->context(attached:-1);
    }
    public function testHostCallBudgetAndLifecycleStress(): void
    {
        $wasm=$this->wat($this->import('input_size'),'(loop call $host drop br 0) i32.const 0');
        $r=$this->runWasm($wasm); $this->assertDiscarded($r); self::assertStringContainsString('Host call budget',$r->runtime->getErrorMessage());
        for ($i=0;$i<50;$i++) {
            $e=new WasmtimeEngine();
            $this->assertDiscarded($e->executeContract($this->fixture('rollback_memory'),$this->context(),new ReadOnlyFixture()));
            self::assertSame('success',$e->executeContract($this->fixture('effects'),$this->context(),new ReadOnlyFixture())->runtime->getStatus());
            unset($e);
        }
    }
    public function testImportPolicyAndRawPathIsolation(): void
    {
        $engine=new WasmtimeEngine();
        foreach ([
            '(import "wasi_snapshot_preview1" "input_size" (func (result i32)))',
            '(import "other" "input_size" (func (result i32)))',
            '(import "greenwood" "unknown" (func (result i32)))',
            '(import "greenwood" "input_size" (func (param i32) (result i32)))',
            '(import "greenwood" "input_size" (func (result i32))) (import "greenwood" "input_size" (func (result i32)))',
            '(import "greenwood" "memory" (memory 1 1))',
        ] as $imports) {
            $r=$this->runWasm(WatCompiler::compile('(module '.$imports.' (func (export "greenwood_call") (result i32) i32.const 0))'));
            $this->assertDiscarded($r); self::assertSame('error',$r->runtime->getStatus());
        }
        $r=$engine->executeContract($this->fixture('effects'),$this->context(),new ReadOnlyFixture(),'missing');
        self::assertSame('missing_export',$r->runtime->getStatus()); $this->assertDiscarded($r);
        $this->expectException(WasmValidationException::class); $engine->execute($this->fixture('input_size'),'greenwood_call',100);
    }
    public function testBoundedStorageAndInfrastructureExceptionsCannotBecomeGuestStatuses(): void
    {
        $one='i32.const 0 i32.const 1 i32.const 1 i32.const 65536 call $host';
        $r=$this->runWasm($this->wat($this->import('storage_set'),$one));
        self::assertSame(0,$r->runtime->getReturnValue()); self::assertSame(65536,strlen($r->overlay->effects()['storageWrites']["\0\0"]));
        $state=new class implements ContractStateViewInterface {
            public function storage(string $c,string $k): ?string { throw new \Greenwood\Contracts\GuestArgumentException('Backend threw a guest-shaped exception',-1); }
            public function accountBalance(string $a): int { return 100; }
            public function contractBalance(string $c): int { return 20; }
        };
        $r=$this->runWasm($this->fixture('storage_get'),$state); $this->assertDiscarded($r); self::assertStringContainsString('Storage backend failure',$r->runtime->getErrorMessage());
        $state=new class implements ContractStateViewInterface {
            public function storage(string $c,string $k): ?string { return null; }
            public function accountBalance(string $a): int { return -1; }
            public function contractBalance(string $c): int { return 20; }
        };
        $this->assertDiscarded($this->runWasm($this->fixture('input_size'),$state));
    }
    public function testNativeCallbackAndLinkerDeclarationsMatchBundledHeaders(): void
    {
        $normalize=static fn(string $s): string=>preg_replace('/\s+/','',str_replace('WASM_API_EXTERN','',$s));
        $decl=WasmtimeHostBindings::declarations();
        $base=__DIR__.'/../../runtime/wasmtime/include/';
        $header=file_get_contents($base.'func.h');
        $pattern='~typedef wasm_trap_t \*\(\*wasmtime_func_callback_t\)\(.*?\);~s';
        self::assertSame(1,preg_match($pattern,$header,$expected));
        self::assertSame(1,preg_match($pattern,$decl,$actual));
        self::assertSame($normalize($expected[0]),$normalize($actual[0]));
        foreach (['linker.h'=>['wasmtime_linker_new','wasmtime_linker_delete','wasmtime_linker_define_func','wasmtime_linker_instantiate'],
                  'func.h'=>['wasmtime_caller_export_get','wasmtime_caller_context'],
                  'memory.h'=>['wasmtime_memory_data','wasmtime_memory_data_size'],
                  'trap.h'=>['wasmtime_trap_new']] as $file=>$names) {
            $header=file_get_contents($base.$file);
            foreach ($names as $name) {
                $pattern='~(?:WASM_API_EXTERN\s+)?(?:[a-z_]+\s+\*?\s*)'.preg_quote($name,'~').'\(.*?\);~s';
                self::assertSame(1,preg_match($pattern,$header,$expected),$name);
                self::assertSame(1,preg_match($pattern,$decl,$actual),$name);
                self::assertSame($normalize($expected[0]),$normalize($actual[0]),$name);
            }
        }
    }
}
