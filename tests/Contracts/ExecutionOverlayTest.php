<?php
declare(strict_types=1);
namespace Greenwood\Tests\Contracts;
require_once __DIR__.'/Support/ReadOnlyFixture.php';
use Greenwood\Contracts\{ContractExecutionContext,ExecutionOverlay,ContractLimits,GuestArgumentException,ContractStatus};
use Greenwood\Tests\Contracts\Support\ReadOnlyFixture;
use PHPUnit\Framework\TestCase;
final class ExecutionOverlayTest extends TestCase
{
    private function overlay(int $accounts=100,int $contracts=20,int $value=10): ExecutionOverlay
    { return new ExecutionOverlay(new ContractExecutionContext(str_repeat('A',32),str_repeat('C',32),'hello',$value,100000),new ReadOnlyFixture($accounts,$contracts)); }
    public function testStoragePrecedenceAndBinaryKeys(): void
    {
        $o=$this->overlay(); self::assertSame('old',$o->storageGet('x'));
        $o->storageSet('x','new'); self::assertSame('new',$o->storageGet('x'));
        $o->storageDelete('x'); self::assertNull($o->storageGet('x'));
        $o->storageSet('x',''); self::assertSame('',$o->storageGet('x'));
        $o->storageDelete('x'); self::assertNull($o->storageGet('x'));
        foreach (['0','00',"\0"] as $key) $o->storageSet($key,$key);
        foreach (['0','00',"\0"] as $key) self::assertSame($key,$o->storageGet($key));
        self::assertSame('old',(new ReadOnlyFixture())->storage(str_repeat('C',32),'x'));
    }
    public function testBalancesAttachedAndTransfers(): void
    {
        $o=$this->overlay(); $o->attachValue(); self::assertSame(90,$o->accountBalance(str_repeat('A',32))); self::assertSame(30,$o->contractBalance());
        $o->transfer(str_repeat('B',32),7); self::assertSame(23,$o->contractBalance()); self::assertSame(107,$o->accountBalance(str_repeat('B',32)));
        $before=$o->effects(); try { $o->transfer(str_repeat('B',32),24); self::fail(); } catch (GuestArgumentException $e) { self::assertSame(ContractStatus::INSUFFICIENT_BALANCE,$e->getCode()); }
        self::assertSame($before,$o->effects());
    }
    public function testCheckedArithmeticAndAtomicRejection(): void
    {
        foreach ([$this->overlay(0),$this->overlay(PHP_INT_MAX,PHP_INT_MAX)] as $o) {
            try { $o->attachValue(); self::fail(); } catch (GuestArgumentException) { self::assertSame([],$o->effects()['accountBalanceDeltas']); }
        }
        $o=$this->overlay(PHP_INT_MAX,20,0); $o->attachValue();
        try { $o->transfer(str_repeat('B',32),1); self::fail(); } catch (GuestArgumentException $e) { self::assertSame(ContractStatus::OVERFLOW,$e->getCode()); }
        self::assertSame([],$o->effects()['contractBalanceDeltas']);
    }
    public function testEventsReturnsLimitsDiscardAndSealing(): void
    {
        $o=$this->overlay(); $o->attachValue(); $o->storageSet('x','new');
        for ($i=0;$i<ContractLimits::MAX_EVENTS_PER_CALL;$i++) $o->emitEvent('topic','data');
        self::assertSame(63,$o->effects()['events'][63]->eventIndex);
        try { $o->emitEvent('',''); self::fail(); } catch (GuestArgumentException $e) { self::assertSame(ContractStatus::LIMIT_EXCEEDED,$e->getCode()); }
        $o->setReturnValue('first'); $o->setReturnValue('last'); self::assertSame('last',$o->effects()['returnValue']);
        try { $o->setReturnValue(str_repeat('a',ContractLimits::MAX_RETURN_BYTES+1)); self::fail(); } catch (GuestArgumentException) { self::assertSame('last',$o->effects()['returnValue']); }
        $o->discard(); self::assertSame(['storageWrites'=>[],'storageDeletes'=>[],'accountBalanceDeltas'=>[],'contractBalanceDeltas'=>[],'events'=>[],'returnValue'=>''],$o->effects());
        $this->expectException(\LogicException::class); $o->storageSet('x','oops');
    }
    public function testAllOverlayBoundsAndNoPartialChanges(): void
    {
        $o=$this->overlay();
        foreach ([['', 'a'],[str_repeat('k',257),'a'],['k',str_repeat('v',65537)]] as [$k,$v]) {
            try { $o->storageSet($k,$v); self::fail(); } catch (GuestArgumentException $e) { self::assertSame(ContractStatus::INVALID_ARGUMENT,$e->getCode()); }
            self::assertSame([],$o->effects()['storageWrites']);
        }
        for ($i=0;$i<ContractLimits::MAX_STORAGE_KEYS_PER_CALL;$i++) $o->storageSet((string)$i,'v');
        try { $o->storageDelete('overflow'); self::fail(); } catch (GuestArgumentException $e) { self::assertSame(ContractStatus::LIMIT_EXCEEDED,$e->getCode()); }
        $o->storageDelete('0'); $o->storageSet('0','updated'); self::assertSame('updated',$o->storageGet('0'));
        $o->setReturnValue(str_repeat('r',ContractLimits::MAX_RETURN_BYTES)); self::assertSame(ContractLimits::MAX_RETURN_BYTES,strlen($o->effects()['returnValue']));
        $o->emitEvent(str_repeat('t',256),str_repeat('d',4096)); self::assertCount(1,$o->effects()['events']);
        foreach ([[str_repeat('t',257),''],['',str_repeat('d',4097)]] as [$t,$d]) {
            try { $o->emitEvent($t,$d); self::fail(); } catch (GuestArgumentException) { self::assertCount(1,$o->effects()['events']); }
        }
        $o=$this->overlay(100,100,0); $o->attachValue();
        for ($i=0;$i<64;$i++) $o->transfer(str_repeat('B',32),1);
        self::assertSame(36,$o->contractBalance());
        try { $o->transfer(str_repeat('B',32),1); self::fail(); } catch (GuestArgumentException $e) { self::assertSame(ContractStatus::LIMIT_EXCEEDED,$e->getCode()); }
        self::assertSame(36,$o->contractBalance());
    }
    public function testContextBoundsAndAttachOnceEvenForZero(): void
    {
        foreach ([[31,32,'',0,1],[32,31,'',0,1],[32,32,str_repeat('x',65537),0,1],[32,32,'',-1,1],[32,32,'',0,-1],[32,32,'',0,1000000001]] as [$a,$c,$input,$value,$gas]) {
            try { new ContractExecutionContext(str_repeat('A',$a),str_repeat('C',$c),$input,$value,$gas); self::fail(); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        $o=$this->overlay(value:0); $o->attachValue();
        $this->expectException(\LogicException::class); $o->attachValue();
    }
}
