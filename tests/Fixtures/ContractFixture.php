<?php
declare(strict_types=1);
namespace Greenwood\Tests\Fixtures;
use Greenwood\Crypto\{Ed25519KeyPair,ProtocolHash};
use Greenwood\Contracts\ContractId;
use Greenwood\State\State;
use Greenwood\Transaction\{DeployContract,CallContract,Envelope};
use Greenwood\Genesis\Genesis;
use Greenwood\Block\Block;
final class ContractFixture
{
    public const CHAIN='contract-vector-v1';
    public static function key(int $seed=4): Ed25519KeyPair
    { $k=sodium_crypto_sign_seed_keypair(str_repeat(chr($seed),32)); return new Ed25519KeyPair(sodium_crypto_sign_publickey($k),sodium_crypto_sign_secretkey($k)); }
    public static function address(int $seed=4): string { return ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',self::key($seed)->getPublicKey()); }
    public static function wasm(string $name): string { return file_get_contents(__DIR__.'/../../test/fixtures/'.$name.'.wasm'); }
    public static function fixturePath(string $name): string { return __DIR__.'/../../test/fixtures/'.$name.'.wasm'; }
    public static function state(): State { return (new State())->setAccountBalance(self::address(),1000000)->recomputeStateRoot(); }
    public static function deploy(State $s,string $name,int $value=0,string $input='',int $gas=100000): Envelope
    { return (new DeployContract(self::wasm($name),$input,$value,$gas))->createEnvelope(self::CHAIN,self::address(),$s->getAccount(self::address())->getNonce(),self::key()); }
    public static function call(State $s,string $name,string $method,string $input='',int $value=0,int $gas=100000): Envelope
    { return (new CallContract(ContractId::fromWasm(self::wasm($name))->bytes,$method,$input,$value,$gas))->createEnvelope(self::CHAIN,self::address(),$s->getAccount(self::address())->getNonce(),self::key()); }
    
    public static function genesis(): Genesis
    {
        $g = new Genesis(self::CHAIN);
        // Add 4 validators with deterministic keys
        $validatorKeys = [];
        for ($i = 0; $i < 4; $i++) {
            $seed = 100 + $i;
            $key = Ed25519KeyPair::generate();
            $validatorKeys[] = $key;
        }
        // Use the same keys for determinism
        $seedA = 100; $seedB = 101; $seedC = 102; $seedD = 103;
        $keyA = Ed25519KeyPair::generate();
        $keyB = Ed25519KeyPair::generate();
        $keyC = Ed25519KeyPair::generate();
        $keyD = Ed25519KeyPair::generate();
        
        $g->addValidator('validator_A', $keyA, 1);
        $g->addValidator('validator_B', $keyB, 1);
        $g->addValidator('validator_C', $keyC, 1);
        $g->addValidator('validator_D', $keyD, 1);
        
        // Add initial account with balance
        $accountKey = self::key();
        $g->addInitialAccount('initial', $accountKey, 1000000);
        
        return $g;
    }
}
