<?php

declare(strict_types=1);

namespace Greenwood\Tests\Integration;

use Greenwood\Crypto\ProtocolHash;
use Greenwood\Crypto\Ed25519KeyPair;
use Greenwood\Genesis\Genesis;
use Greenwood\Block\Header;
use Greenwood\Block\Block;
use Greenwood\State\State;
use Greenwood\Persistence\BlockFilesystem;
use Greenwood\Persistence\StateSqlite;
use PHPUnit\Framework\TestCase;

class Pass1Test extends TestCase
{
    private string $testDir;
    
    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/greenwood_test_' . uniqid();
        mkdir($this->testDir . '/blocks', 0755, true);
        mkdir($this->testDir . '/state', 0755, true);
    }
    
    protected function tearDown(): void
    {
        // Don't clean up for debugging
    }
    
    public function testProtocolHashEmpty(): void
    {
        $hash = ProtocolHash::hash('TEST_DOMAIN', '');
        $this->assertEquals(32, strlen($hash));
    }
    
    public function testProtocolHashDeterministic(): void
    {
        $hash1 = ProtocolHash::hash('TEST', 'data');
        $hash2 = ProtocolHash::hash('TEST', 'data');
        $this->assertEquals($hash1, $hash2);
    }
    
    public function testEd25519KeyGeneration(): void
    {
        $keyPair = Ed25519KeyPair::generate();
        $this->assertEquals(32, strlen($keyPair->getPublicKey()));
        $this->assertEquals(64, strlen($keyPair->getPrivateKey()));
    }
    
    public function testAccountAddressDerivation(): void
    {
        $keyPair = Ed25519KeyPair::generate();
        $publicKey = $keyPair->getPublicKey();
        $address = ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $publicKey);
        $this->assertEquals(32, strlen($address));
    }
    
    public function testCreateGenesis(): void
    {
        $validatorA = Ed25519KeyPair::generate();
        $validatorB = Ed25519KeyPair::generate();
        $validatorC = Ed25519KeyPair::generate();
        $validatorD = Ed25519KeyPair::generate();
        
        $genesis = new Genesis('greenwood-local-v1');
        $genesis->addValidator('A', $validatorA, 1);
        $genesis->addValidator('B', $validatorB, 1);
        $genesis->addValidator('C', $validatorC, 1);
        $genesis->addValidator('D', $validatorD, 1);
        
        $block = $genesis->createGenesisBlock();
        
        $this->assertEquals(0, $block->getHeader()->getHeight());
        $this->assertEquals('greenwood-local-v1', $block->getHeader()->getChainId());
        $this->assertEquals(32, strlen($block->getHash()));
        
        // Verify block can be serialized and deserialized
        $bytes = $block->toBytes();
        $loaded = Block::fromBytes($bytes);
        $this->assertEquals($block->getHash(), $loaded->getHash());
    }
    
    public function testBlockPersistence(): void
    {
        $filesystem = new BlockFilesystem($this->testDir . '/blocks');
        
        $validator = Ed25519KeyPair::generate();
        $genesis = new Genesis('test');
        $genesis->addValidator('A', $validator, 1);
        $block = $genesis->createGenesisBlock();
        
        $filesystem->saveBlock($block);
        
        $loaded = $filesystem->loadBlock(0);
        $this->assertNotNull($loaded);
        $this->assertEquals($block->getHash(), $loaded->getHash());
    }
    
    public function testStateSqlite(): void
    {
        $sqlite = new StateSqlite($this->testDir . '/state/test.sqlite');
        
        $address = ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', str_repeat('a', 32));
        $sqlite->upsertAccount($address, 1000, 0);
        
        $account = $sqlite->getAccount($address);
        $this->assertNotNull($account);
        $this->assertEquals(1000, $account['balance']);
        $this->assertEquals(0, $account['nonce']);
        
        // Verify state root computation works
        $stateRoot = $sqlite->computeStateRoot();
        $this->assertEquals(32, strlen($stateRoot));
    }
    
    public function testGenesisToStateAndBack(): void
    {
        // Create genesis
        $validator = Ed25519KeyPair::generate();
        $genesis = new Genesis('test');
        $genesis->addValidator('A', $validator, 1);
        $genesis->addInitialAccount('A', $validator, 1000000);
        $block = $genesis->createGenesisBlock();
        
        // Save block
        $filesystem = new BlockFilesystem($this->testDir . '/blocks');
        $filesystem->saveBlock($block);
        
        // Create state from genesis
        $state = new State();
        $state->setAccountBalance($block->getHeader()->getProposer(), 1000000);
        $state->setAccountNonce($block->getHeader()->getProposer(), 0);
        $state->recomputeStateRoot();
        
        // Verify state root is valid (32 bytes, deterministic)
        $stateRoot = $state->getStateRoot();
        $this->assertEquals(32, strlen($stateRoot));
        
        // Verify the state root is deterministic
        $state2 = new State();
        $state2->setAccountBalance($block->getHeader()->getProposer(), 1000000);
        $state2->setAccountNonce($block->getHeader()->getProposer(), 0);
        $state2->recomputeStateRoot();
        $this->assertEquals($stateRoot, $state2->getStateRoot());
    }
}
