<?php
declare(strict_types=1);
namespace Greenwood\Tests\Fixtures;
use Greenwood\Crypto\{Ed25519KeyPair,ProtocolHash};
use Greenwood\Genesis\Genesis;
use Greenwood\Transaction\Transfer;
use Greenwood\Block\{Block,Header};
use Greenwood\Consensus\Messages\{Message,Certificate};
final class ProtocolFixture
{
    public static function values(): array
    {
        $keys=[];
        for ($i=0;$i<6;$i++) {
            $kp=sodium_crypto_sign_seed_keypair(str_repeat(chr($i),32));
            $keys[]=new Ed25519KeyPair(sodium_crypto_sign_publickey($kp),sodium_crypto_sign_secretkey($kp));
        }
        $g=new Genesis('greenwood-vector-v1');
        foreach (['A','B','C','D'] as $i=>$id) $g->addValidator($id,$keys[$i]);
        $g->addInitialAccount('sender',$keys[4],1000000);
        $address=fn($key)=>ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',$key->getPublicKey());
        $tx=(new Transfer($address($keys[5]),12345))->createEnvelope($g->getChainId(),$address($keys[4]),0,$keys[4]);
        $initial=$g->initialState(); $state=clone $initial; $state->applyTransaction($tx,$g->getChainId());
        $block=new Block(new Header(1,$g->getChainId(),1,0,$g->createGenesisBlock()->getHash(),$initial->getStateRoot(),ProtocolHash::merkleRoot([$tx->getHash()]),$state->getStateRoot(),$address($keys[1]),$g->getGenesisHash(),0),[$tx]);
        $out=['seed_rule'=>'validator A/B/C/D use 32 repetitions of 00/01/02/03; sender 04; recipient 05 (hex bytes)',
            'sender_public_key'=>bin2hex($keys[4]->getPublicKey()),'recipient_public_key'=>bin2hex($keys[5]->getPublicKey()),'address'=>bin2hex($address($keys[4])),
            'genesis_encoding'=>bin2hex($g->getCanonicalBytes()),'genesis_hash'=>bin2hex($g->getGenesisHash()),'genesis_block_encoding'=>bin2hex($g->createGenesisBlock()->toBytes()),'genesis_block_hash'=>bin2hex($g->createGenesisBlock()->getHash()),
            'initial_state_root'=>bin2hex($initial->getStateRoot()),'transaction_unsigned'=>bin2hex($tx->getSigningBytes()),'transaction_digest'=>bin2hex($tx->getSigningDigest()),
            'transaction_encoding'=>bin2hex($tx->getCanonicalBytes()),'transaction_hash'=>bin2hex($tx->getHash()),'block_header'=>bin2hex($block->getHeader()->toBytes()),
            'block_encoding'=>bin2hex($block->toBytes()),'block_hash'=>bin2hex($block->getHash()),'state_root'=>bin2hex($state->getStateRoot())];
        foreach (['proposal'=>['B',1],'prevote'=>['A',0],'precommit'=>['C',2]] as $kind=>[$id,$index]) {
            $m=new Message($kind,$g->getChainId(),$g->getGenesisHash(),1,0,$id,$block->getHash(),$kind==='proposal'?$block->toBytes():'');
            $out[$kind.'_unsigned']=bin2hex($m->unsigned()); $out[$kind.'_digest']=bin2hex($m->digest()); $out[$kind.'_encoding']=bin2hex($m->signed($keys[$index]->sign($m->digest()))->bytes());
        }
        $votes=[];
        foreach (['A','B','C'] as $i=>$id) { $m=new Message('precommit',$g->getChainId(),$g->getGenesisHash(),1,0,$id,$block->getHash()); $votes[]=$m->signed($keys[$i]->sign($m->digest())); }
        $out['finality_certificate']=bin2hex((new Certificate($votes))->bytes());
        $out['wasm']='0061736d01000000'; $out['contract_id']=bin2hex(ProtocolHash::hash('GREENWOOD_CONTRACT_V1',hex2bin($out['wasm'])));
        return $out;
    }
}
