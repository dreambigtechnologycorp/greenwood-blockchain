<?php
declare(strict_types=1);
namespace Greenwood\Tests\Integration;
use PHPUnit\Framework\TestCase;
use Greenwood\Crypto\{Ed25519KeyPair,ProtocolHash};
use Greenwood\Genesis\Genesis;
use Greenwood\Node\Chain;
use Greenwood\Persistence\{BlockFilesystem,StateSqlite};
use Greenwood\Transaction\{Transfer,Envelope};
use Greenwood\Block\Block;
final class RecoveryTest extends TestCase
{
    public function testBoundedReplayVerifiesEveryCertificateAndRebuildsIdenticalState(): void
    {
        $dir=sys_get_temp_dir().'/gw-bounded-replay-'.bin2hex(random_bytes(6));
        $g=new Genesis('bounded-replay'); $key=Ed25519KeyPair::generate();
        $g->addValidator('A',$key);
        $files=new BlockFilesystem($dir.'/blocks');
        $chain=new Chain($g,$files,new StateSqlite($dir.'/state.sqlite'));
        self::assertSame(0,$chain->tip->getHeight());
        $proposer=ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',$key->getPublicKey());
        for($h=1;$h<=4;$h++) $chain->finalize($chain->build([],0,$proposer),'certificate-'.$h);
        $verified=[];
        $verify=static function(Block $block,?string $certificate)use(&$verified):void {
            $height=$block->getHeight();
            if($certificate!=='certificate-'.$height)throw new \RuntimeException('Certificate rejected');
            $verified[]=$height;
        };
        $replayed=new Chain($g,$files,new StateSqlite($dir.'/rebuilt.sqlite'),$verify);
        self::assertSame([1,2,3,4],$verified);
        self::assertSame($chain->tip->getHash(),$replayed->tip->getHash());
        self::assertSame($chain->state->getStateRoot(),$replayed->state->getStateRoot());
        unlink($dir.'/blocks/0000000000000003-cert.bin');
        $this->expectExceptionMessage('Certificate rejected');
        new Chain($g,$files,new StateSqlite($dir.'/rejected.sqlite'),$verify);
    }
    public function testBoundedReplayRejectsGapBeforeCertificateVerification(): void
    {
        $dir=sys_get_temp_dir().'/gw-replay-gap-'.bin2hex(random_bytes(6));
        $g=new Genesis('replay-gap'); $key=Ed25519KeyPair::generate(); $g->addValidator('A',$key);
        $files=new BlockFilesystem($dir.'/blocks');
        $chain=new Chain($g,$files,new StateSqlite($dir.'/state.sqlite'));
        $proposer=ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',$key->getPublicKey());
        for($h=1;$h<=3;$h++)$chain->finalize($chain->build([],0,$proposer));
        unlink($dir.'/blocks/0000000000000002-block.bin');
        $called=false;
        try {
            new Chain($g,$files,new StateSqlite($dir.'/rejected.sqlite'),static function()use(&$called):void{$called=true;});
            self::fail('Gap accepted');
        } catch(\RuntimeException $e) {
            self::assertSame('Gap in authoritative history',$e->getMessage());
            self::assertFalse($called);
        }
    }
    public function testCompleteRecovery(): void
    {
        $dir = sys_get_temp_dir().'/greenwood-replay-'.bin2hex(random_bytes(6));
        $g = new Genesis('replay-v1'); $keys = [];
        foreach (['A','B','C','D'] as $id) { $keys[$id]=Ed25519KeyPair::generate(); $g->addValidator($id,$keys[$id]); }
        $sender=Ed25519KeyPair::generate(); $recipient=Ed25519KeyPair::generate();
        $g->addInitialAccount('sender',$sender,1000000);
        $from=ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',$sender->getPublicKey());
        $to=ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',$recipient->getPublicKey());
        $files=new BlockFilesystem($dir.'/blocks'); $db=new StateSqlite($dir.'/state.sqlite');
        $chain=new Chain($g,$files,$db);
        $tx=(new Transfer($to,10000))->createEnvelope('replay-v1',$from,0,$sender);
        $tx=Envelope::decode($tx->getCanonicalBytes()); $tx->verify('replay-v1');
        $block=$chain->build([$tx],0,ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',$keys['B']->getPublicKey()));
        $block=Block::fromBytes($block->toBytes());
        self::assertSame(1000000,$chain->state->getAccount($from)->getBalance(),'Speculative execution must not mutate finalized state');
        $chain->finalize($block);
        self::assertSame(990000,$db->getBalance($from)); self::assertSame(10000,$db->getBalance($to)); self::assertSame(1,$db->getNonce($from));
        $root=$chain->state->getStateRoot(); $hash=$chain->tip->getHash();
        self::assertSame($root,$db->computeStateRoot());
        $db->close(); unset($chain,$db);
        $db=new StateSqlite($dir.'/state.sqlite'); $restart=new Chain($g,$files,$db);
        self::assertSame($root,$restart->state->getStateRoot()); self::assertSame($hash,$restart->tip->getHash());
        $db->close(); unset($restart,$db); unlink($dir.'/state.sqlite');
        $db=new StateSqlite($dir.'/state.sqlite'); $rebuilt=new Chain($g,$files,$db);
        self::assertSame($root,$rebuilt->state->getStateRoot()); self::assertSame($root,$db->computeStateRoot());
        self::assertSame($hash,$rebuilt->tip->getHash()); self::assertSame(1,$rebuilt->tip->getHeight());
        self::assertSame(990000,$db->getBalance($from)); self::assertSame(10000,$db->getBalance($to)); self::assertSame(1,$db->getNonce($from));
        $this->expectException(\InvalidArgumentException::class);
        $rebuilt->state->applyTransaction($tx,'replay-v1');
    }
    public function testCorruptIndexRebuildAndCorruptHistoryFailsClosed(): void
    {
        $dir=sys_get_temp_dir().'/gw-corruption-'.bin2hex(random_bytes(6));
        $g=new Genesis('test'); $key=Ed25519KeyPair::generate();
        foreach (['A','B','C','D'] as $id) $g->addValidator($id,Ed25519KeyPair::generate());
        $g->addInitialAccount('funded',$key,100);
        $files=new BlockFilesystem($dir.'/blocks');
        file_put_contents($dir.'/state.sqlite','not a SQLite database');
        $chain=new Chain($g,$files,new StateSqlite($dir.'/state.sqlite'));
        self::assertSame($g->initialState()->getStateRoot(),$chain->state->getStateRoot());
        self::assertCount(1,glob($dir.'/state.sqlite.corrupt.*'));
        file_put_contents($dir.'/blocks/0000000000000000-block.bin','corrupt history');
        $this->expectException(\InvalidArgumentException::class);
        new Chain($g,$files,new StateSqlite($dir.'/state.sqlite'));
    }
    public function testStateRootCommitsToKeysNoncesAndValidators(): void
    {
        $a=new \Greenwood\State\State(); $a->setAccountBalance(str_repeat('a',32),7)->recomputeStateRoot();
        $b=new \Greenwood\State\State(); $b->setAccountBalance(str_repeat('b',32),7)->recomputeStateRoot();
        self::assertNotSame($a->getStateRoot(),$b->getStateRoot());
        $old=$a->getStateRoot(); $a->setAccountNonce(str_repeat('a',32),1)->recomputeStateRoot(); self::assertNotSame($old,$a->getStateRoot());
        $old=$a->getStateRoot(); $a->setValidators(['A'=>['publicKey'=>str_repeat('k',32),'votingPower'=>1]])->recomputeStateRoot(); self::assertNotSame($old,$a->getStateRoot());
    }
    public function testMalformedTransactionsAndAuthorization(): void
    {
        $key=Ed25519KeyPair::generate(); $from=ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',$key->getPublicKey());
        $tx=(new Transfer(str_repeat('b',32),5))->createEnvelope('test',$from,0,$key);
        $bytes=$tx->getCanonicalBytes();
        foreach ([substr($bytes,0,-1),$bytes.'x',substr_replace($bytes,"\xff\xff",1,2)] as $bad) {
            try { Envelope::decode($bad); self::fail('Malformed transaction accepted'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        foreach (['wrong-chain','test'] as $chain) {
            $copy=Envelope::decode($bytes);
            if ($chain==='test') $copy->setSignature(str_repeat('x',64));
            try { $copy->verify($chain); self::fail('Unauthorized transaction accepted'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
