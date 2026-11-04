<?php
declare(strict_types=1);
namespace Greenwood\Tests\Contracts;
use Greenwood\Contracts\{WasmtimeEngine,WasmtimeConfig,WasmtimeBindings,WasmValidationException,WasmExecutionResult};
use PHPUnit\Framework\TestCase;

final class WasmtimeIntegrationTest extends TestCase
{
    private function wasm(string $name='valid'): string { return file_get_contents(__DIR__.'/../../test/fixtures/'.$name.'.wasm'); }
    public function testNativeValidationAndInvocation(): void
    {
        $engine=new WasmtimeEngine();
        $engine->validate($this->wasm());
        $result=$engine->execute($this->wasm(),'greenwood_call',1000);
        self::assertSame('48.0.1',$engine->getVersion());
        self::assertSame('success',$result->getStatus()); self::assertSame(42,$result->getReturnValue()); self::assertSame(2,$result->getFuelConsumed());
    }
    public function testDeterminism100Times(): void
    {
        $engine=new WasmtimeEngine();
        for ($i=0;$i<100;$i++) {
            $r=$engine->execute($this->wasm(),'greenwood_call',1000);
            self::assertSame(['success',42,2],[$r->getStatus(),$r->getReturnValue(),$r->getFuelConsumed()]);
        }
    }
    public function testFuelExhaustionTrapAndRecovery(): void
    {
        $e=new WasmtimeEngine();
        for ($i=0;$i<10;$i++) {
            $exhausted=$e->execute($this->wasm('loop'),'greenwood_call',100);
            self::assertSame('fuel_exhausted',$exhausted->getStatus()); self::assertSame(100,$exhausted->getFuelConsumed());
            $trap=$e->execute($this->wasm('trap'),'greenwood_call',100);
            self::assertSame('trap',$trap->getStatus()); self::assertFalse($trap->isFuelExhausted()); self::assertGreaterThan(0,$trap->getFuelConsumed());
            self::assertSame(42,$e->execute($this->wasm(),'greenwood_call',100)->getReturnValue());
        }
        self::assertSame('fuel_exhausted',$e->execute($this->wasm(),'greenwood_call',0)->getStatus());
        self::assertSame('success',$e->execute($this->wasm(),'greenwood_call',2)->getStatus());
    }
    public function testMissingExportWrongKindAndSignature(): void
    {
        $e=new WasmtimeEngine();
        self::assertSame('missing_export',$e->execute($this->wasm(),'absent',100)->getStatus());
        self::assertSame('error',$e->execute($this->wasm('memory'),'memory',100)->getStatus());
        self::assertSame('error',$e->execute($this->wasm('wrong_signature'),'greenwood_call',100)->getStatus());
        self::assertSame(42,$e->execute($this->wasm(),'greenwood_call',100)->getReturnValue());
    }
    public function testPolicyRejectsNativeValidWasiFloatsAndUnboundedResources(): void
    {
        WasmtimeBindings::load(); $ffi=WasmtimeBindings::get(); $native=$ffi->wasm_engine_new(); $e=new WasmtimeEngine();
        try {
            foreach (['wasi','float','oversized_memory','oversized_table'] as $name) {
                $wasm=$this->wasm($name); $buffer=$ffi->new('uint8_t['.strlen($wasm).']'); \FFI::memcpy($buffer,$wasm,strlen($wasm));
                self::assertNull($ffi->wasmtime_module_validate($native,$buffer,strlen($wasm)),'Fixture must be valid WASM: '.$name);
                try { $e->validate($wasm); self::fail('Forbidden module accepted: '.$name); } catch (WasmValidationException $error) { self::assertNotSame('',$error->getMessage()); }
                try { $e->execute($wasm,'greenwood_call',100); self::fail('Execution bypassed policy'); } catch (WasmValidationException) { self::assertTrue(true); }
            }
        } finally { $ffi->wasm_engine_delete($native); }
    }
    public function testMalformedFixtureCannotPassAndRecoveryWorks(): void
    {
        $e=new WasmtimeEngine();
        foreach (['',"notwasm",hex2bin('0061736d0100000001040160000003020100070801046d61696e00000a040103000b'),$this->wasm().'x'] as $wasm) {
            try { $e->validate($wasm); self::fail('Malformed module accepted'); } catch (WasmValidationException) { self::assertTrue(true); }
        }
        self::assertSame(42,$e->execute($this->wasm(),'greenwood_call',100)->getReturnValue());
    }
    public function testMemoryBoundsInteger64AndDataBytes(): void
    {
        $e=new WasmtimeEngine();
        self::assertSame(-1,$e->execute($this->wasm('memory'),'greenwood_call',100)->getReturnValue());
        self::assertSame(-9223372036854775807,$e->execute($this->wasm('int64'),'greenwood_call',100)->getReturnValue());
        // The data fixture costs exactly 9 fuel under the engine's pinned
        // fuel schedule (CoW memory-init disabled, so memory-instantiation
        // accounting is identical on every platform — Wasmtime 48.0.1 with
        // CoW enabled reported 3 on Linux vs 16388 on macOS; with CoW
        // disabled both platforms report the byte-identical curve below).
        // Invariants asserted: deterministic exhaustion below the cost, and
        // exact deterministic consumption on every repetition. (Upstream
        // detail: the instantiation charge saturates at the granted limit,
        // so limit 8 succeeds consuming 8; the module's full cost is 9.)
        self::assertSame('fuel_exhausted',$e->execute($this->wasm('data'),'greenwood_call',7)->getStatus());
        self::assertSame(7,$e->execute($this->wasm('data'),'greenwood_call',7)->getFuelConsumed());
        self::assertSame('success',$e->execute($this->wasm('data'),'greenwood_call',8)->getStatus());
        self::assertSame(8,$e->execute($this->wasm('data'),'greenwood_call',8)->getFuelConsumed());
        self::assertSame('success',$e->execute($this->wasm('data'),'greenwood_call',9)->getStatus());
        for ($i=0;$i<10;$i++) {
            $r=$e->execute($this->wasm('data'),'greenwood_call',100000);
            self::assertSame([0x43,9],[$r->getReturnValue(),$r->getFuelConsumed()]);
        }
    }
    public function testConcreteLayoutsMatchBundledHeaders(): void
    {
        $declarations=WasmtimeConfig::buildFFIDeclarations();
        $normalize=static function(string $s): string {
            $s=preg_replace('~//[^\n]*|/\*.*?\*/~s','',$s);
            $s=preg_replace('/^#.*$/m','',$s);
            return preg_replace('/\s+/','',$s);
        };
        foreach (['instance.h'=>['instance'],'tag.h'=>['tag'],'extern.h'=>['func','table','memory','global','extern_union','extern'],'val.h'=>['anyref','exnref','externref','valunion','val']] as $file=>$names) {
            $header=file_get_contents(__DIR__.'/../../runtime/wasmtime/include/'.$file);
            foreach ($names as $name) {
                $pattern='/typedef (?:struct|union) wasmtime_'.preg_quote($name,'/').' \{.*?\} wasmtime_'.preg_quote($name,'/').'_t;/s';
                self::assertSame(1,preg_match($pattern,$header,$expected));
                self::assertSame(1,preg_match($pattern,$declarations,$actual));
                self::assertSame($normalize($expected[0]),$normalize($actual[0]),$name.' layout differs from bundled header');
            }
        }
        self::assertStringNotContainsString('wasmtime_instance_delete',$declarations);
    }
    public function testLifecycleStress(): void
    {
        for ($i=0;$i<50;$i++) {
            $e=new WasmtimeEngine();
            self::assertSame('trap',$e->execute($this->wasm('trap'),'greenwood_call',100)->getStatus());
            self::assertSame('missing_export',$e->execute($this->wasm(),'absent',100)->getStatus());
            self::assertSame('error',$e->execute($this->wasm('wrong_signature'),'greenwood_call',100)->getStatus());
            self::assertSame(42,$e->execute($this->wasm(),'greenwood_call',100)->getReturnValue());
            unset($e);
        }
    }
}
