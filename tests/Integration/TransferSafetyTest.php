<?php
declare(strict_types=1);
namespace Greenwood\Tests\Integration;
use PHPUnit\Framework\TestCase;
use Greenwood\Crypto\{Ed25519KeyPair,ProtocolHash};
use Greenwood\Transaction\{Envelope,Transfer};
use Greenwood\State\State;
final class TransferSafetyTest extends TestCase
{
    public function testInvalidTransfersDoNotMutateState(): void
    {
        $key=Ed25519KeyPair::generate(); $sender=ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',$key->getPublicKey()); $to=str_repeat('r',32);
        $state=(new State())->setAccountBalance($sender,100)->setAccountNonce($sender,1)->setAccountBalance($to,PHP_INT_MAX)->recomputeStateRoot();
        foreach ([[0,1,$sender],[2,1,$sender],[1,0,$sender],[1,101,$sender],[1,1,str_repeat('f',32)],[1,1,$sender]] as [$nonce,$amount,$from]) {
            $tx=new Envelope(1,'test',$from,$nonce,1,$to.pack('N',$amount),'',$key->getPublicKey()); $tx->setSignature($key->sign($tx->getSigningDigest()));
            $root=$state->getStateRoot();
            try { $state->applyTransaction($tx,'test'); self::fail('Invalid transfer accepted'); } catch (\InvalidArgumentException) { self::assertSame($root,$state->recomputeStateRoot()->getStateRoot()); }
            self::assertSame(100,$state->getAccount($sender)->getBalance()); self::assertSame(1,$state->getAccount($sender)->getNonce());
        }
    }
    public function testSelfTransferAndMultipleTransactionDecoding(): void
    {
        $key=Ed25519KeyPair::generate(); $sender=ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',$key->getPublicKey());
        $state=(new State())->setAccountBalance($sender,100)->recomputeStateRoot();
        $tx=(new Transfer($sender,100))->createEnvelope('test',$sender,0,$key); $state->applyTransaction($tx,'test');
        self::assertSame(100,$state->getAccount($sender)->getBalance()); self::assertSame(1,$state->getAccount($sender)->getNonce());
        $second=(new Transfer(str_repeat('r',32),10))->createEnvelope('test',$sender,1,$key);
        $header=new \Greenwood\Block\Header(1,'test',1,0,str_repeat('a',32),str_repeat('b',32),ProtocolHash::merkleRoot([$tx->getHash(),$second->getHash()]),str_repeat('s',32),$sender,str_repeat('g',32),0);
        $block=new \Greenwood\Block\Block($header,[$tx,$second]);
        $decoded=\Greenwood\Block\Block::fromBytes($block->toBytes());
        self::assertSame([$tx->getHash(),$second->getHash()],array_map(fn($t)=>$t->getHash(),$decoded->getTransactions()));
    }
}
