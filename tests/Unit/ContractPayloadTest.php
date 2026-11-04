<?php
declare(strict_types=1);
namespace Greenwood\Tests\Unit;
use PHPUnit\Framework\TestCase;
use Greenwood\Transaction\{DeployContract,CallContract,Envelope};
use Greenwood\Contracts\{ContractId,ContractTransactionLimits as L};
use Greenwood\Codec\Canonical as C;
use Greenwood\Tests\Fixtures\ContractFixture as F;
final class ContractPayloadTest extends TestCase
{
    public function testGoldenBytesAndExistingAuthentication(): void
    {
        $wasm=hex2bin('0061736d01000000');
        $d=new DeployContract($wasm,'',0,100);
        self::assertSame('000000080061736d010000000000000000000000000000000000000000000064',bin2hex($d->serialize()));
        $c=new CallContract(str_repeat("\x11",32),'set',"\0x",2,3);
        self::assertSame(str_repeat('11',32).'0000000373657400000002007800000000000000020000000000000003',bin2hex($c->serialize()));
        foreach ([$d,$c] as $p) {
            self::assertEquals($p,$p::decode($p->serialize()));
            $tx=$p->createEnvelope(F::CHAIN,F::address(),0,F::key()); $copy=Envelope::decode($tx->getCanonicalBytes()); $copy->verify(F::CHAIN);
            self::assertSame($tx->getHash(),$copy->getHash()); self::assertSame($tx->getSigningDigest(),$copy->getSigningDigest());
        }
        $old=json_decode(file_get_contents(__DIR__.'/../Fixtures/protocol-v1.json'),true);
        self::assertSame($old['contract_id'],ContractId::fromWasm($wasm)->toHex());
        self::assertNotSame(ContractId::fromWasm($wasm)->bytes,ContractId::fromWasm($wasm."\0")->bytes);
        self::assertNotSame(ContractId::fromWasm($wasm)->bytes,ContractId::fromWasm(bin2hex($wasm))->bytes);
    }
    public function testBoundariesAndExactDecoders(): void
    {
        $payloads=[new DeployContract('a','',0,0),new DeployContract(str_repeat('a',L::MAX_WASM_BYTES),str_repeat('i',65536),PHP_INT_MAX,1000000000),
            new CallContract(str_repeat('c',32),'a','',0,0),new CallContract(str_repeat('c',32),str_repeat('a',64),str_repeat('d',L::MAX_CALLDATA_BYTES),PHP_INT_MAX,1000000000)];
        foreach ($payloads as $p) {
            $bytes=$p->serialize(); self::assertEquals($p,$p::decode($bytes));
            $tx=$p->createEnvelope(F::CHAIN,F::address(),0,F::key()); self::assertSame($tx->getCanonicalBytes(),Envelope::decode($tx->getCanonicalBytes())->getCanonicalBytes());
            foreach ([$bytes.'x',substr($bytes,0,-1),substr($bytes,0,1),substr_replace($bytes,str_repeat("\xff",8),-8,8)] as $bad) {
                try { $p::decode($bad); self::fail(); } catch (\InvalidArgumentException) { self::assertTrue(true); }
            }
        }
        self::assertSame(65536,strlen($payloads[3]->invocationInput()));
        self::assertSame(C::encodeBytes('set').C::encodeBytes('data'),(new CallContract(str_repeat('c',32),'set','data',0,0))->invocationInput());
    }
    public function testInvalidFields(): void
    {
        $bad=[fn()=>new DeployContract('','',0,0),fn()=>new DeployContract(str_repeat('a',L::MAX_WASM_BYTES+1),'',0,0),fn()=>new DeployContract('a',str_repeat('a',65537),0,0),fn()=>new DeployContract('a','',-1,0),fn()=>new DeployContract('a','',0,1000000001),fn()=>new DeployContract('a','',0,-1)];
        foreach (['','1bad','a-b','é','a.b',str_repeat('a',65)] as $m) $bad[]=fn()=>new CallContract(str_repeat('c',32),$m,'',0,0);
        foreach ([31,33] as $n) $bad[]=fn()=>new CallContract(str_repeat('c',$n),'ok','',0,0);
        $bad[]=fn()=>new CallContract(str_repeat('c',32),'ok',str_repeat('a',L::MAX_CALLDATA_BYTES+1),0,0);
        $bad[]=fn()=>new CallContract(str_repeat('c',32),'ok','',-1,0);
        $bad[]=fn()=>new CallContract(str_repeat('c',32),'ok','',0,1000000001);
        foreach ($bad as $make) { try { $make(); self::fail(); } catch (\InvalidArgumentException) { self::assertTrue(true); } }
        foreach ([2,3] as $type) {
            $payload=$type===2?new DeployContract('w','',0,0):new CallContract(str_repeat('c',32),'m','',0,0);
            $bytes=$payload->serialize(); $bytes=substr_replace($bytes,"\x80".str_repeat("\0",7),-16,8);
            try { $payload::decode($bytes); self::fail(); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
