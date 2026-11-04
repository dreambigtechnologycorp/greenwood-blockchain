<?php
declare(strict_types=1);
namespace Greenwood\Tests\Consensus;
use PHPUnit\Framework\TestCase;
use Greenwood\Consensus\Journal\SafetyJournal;
use Greenwood\Consensus\Messages\{Message,Certificate};
use Greenwood\Crypto\Ed25519KeyPair;
use Greenwood\Consensus\Network\Transport;
final class SafetyTest extends TestCase
{
    public function testDurableSigningAndHeightScopedLock(): void
    {
        $key=Ed25519KeyPair::generate(); $genesis=str_repeat('g',32); $path=tempnam(sys_get_temp_dir(),'gw-journal-');
        $j=new SafetyJournal($path,$key,$genesis,'A');
        $m=new Message('prevote','test',$genesis,1,0,'A',str_repeat('a',32));
        $signed=$j->sign($m); $j->lock(1,0,'block','proof'); unset($j);
        $restart=new SafetyJournal($path,$key,$genesis,'A');
        self::assertSame($signed->bytes(),$restart->sign($m)->bytes());
        self::assertSame('block',$restart->getLock(1)['block']); self::assertNull($restart->getLock(2));
        self::assertCount(1,$restart->messages(1));
        $this->expectException(\RuntimeException::class);
        $restart->sign(new Message('prevote','test',$genesis,1,0,'A',str_repeat('b',32)));
    }
    public function testCertificateAuthorizesOnlyItsExactTargetPositionAndUniqueSigners(): void
    {
        $validators=[]; $votes=[]; $genesis=str_repeat('g',32); $target=str_repeat('t',32);
        foreach (['A','B','C','D'] as $id) {
            $key=Ed25519KeyPair::generate(); $validators[$id]=['publicKey'=>$key->getPublicKey(),'votingPower'=>1];
            $m=new Message('precommit','test',$genesis,2,3,$id,$target); $votes[]=$m->signed($key->sign($m->digest()));
        }
        $cert=Certificate::decode((new Certificate(array_slice($votes,0,3)))->bytes());
        self::assertSame(3,$cert->verify($validators,'test',$genesis,'precommit',2,$target));
        foreach ([[new Certificate([$votes[0],$votes[1]]),'precommit',2,$target],[new Certificate([$votes[0],$votes[0],$votes[2]]),'precommit',2,$target],[$cert,'prevote',2,$target],[$cert,'precommit',3,$target],[$cert,'precommit',2,str_repeat('x',32)]] as [$bad,$kind,$height,$hash]) {
            try { $bad->verify($validators,'test',$genesis,$kind,$height,$hash); self::fail('Invalid certificate accepted'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
    public function testStrictMessageAndTransportBoundaries(): void
    {
        $key=Ed25519KeyPair::generate(); $m=new Message('prevote','test',str_repeat('g',32),1,0,'A',''); $m=$m->signed($key->sign($m->digest()));
        self::assertSame($m->bytes(),Message::decode(Transport::unwrap(Transport::envelope('prevote',$m->bytes()),'prevote'))->bytes());
        foreach ([substr($m->bytes(),0,-1),$m->bytes().'x',str_repeat('a',1100001)] as $bad) {
            try { Message::decode($bad); self::fail('Malformed message accepted'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
