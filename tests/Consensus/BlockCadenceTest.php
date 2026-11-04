<?php
declare(strict_types=1);
namespace Greenwood\Tests\Consensus;

use Greenwood\Consensus\Journal\SafetyJournal;
use Greenwood\Consensus\Runtime\ConsensusDriver;
use Greenwood\Crypto\Ed25519KeyPair;
use Greenwood\Genesis\Genesis;
use Greenwood\Node\{Chain, ChainConfig};
use Greenwood\Persistence\{BlockFilesystem, StateSqlite};
use PHPUnit\Framework\TestCase;

final class BlockCadenceTest extends TestCase
{
    private string $dir;
    private array $nodes = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gw-cadence-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        $genesis = new Genesis('cadence-regression');
        $keys = [];
        foreach (['A', 'B', 'C'] as $id) {
            $keys[$id] = Ed25519KeyPair::generate();
            $genesis->addValidator($id, $keys[$id]);
        }
        foreach ($keys as $id => $key) {
            $path = $this->dir . '/' . $id;
            mkdir($path, 0700);
            $chain = new Chain($genesis, new BlockFilesystem($path . '/blocks'), new StateSqlite($path . '/state.sqlite'));
            $journal = new SafetyJournal($path . '/signing.sqlite', $key, $genesis->getGenesisHash(), $id);
            $driver = new ConsensusDriver($chain, $id, [], $journal, 'http://127.0.0.1:0', new ChainConfig());
            $this->nodes[$id] = compact('chain', 'journal', 'driver');
        }
    }

    protected function tearDown(): void
    {
        $this->nodes = [];
        gc_collect_cycles();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->dir);
    }

    private function set(ConsensusDriver $driver, string $name, mixed $value): void
    {
        (new \ReflectionProperty($driver, $name))->setValue($driver, $value);
    }

    public function testRoundTimeoutCannotOpenTheHeightCadenceGate(): void
    {
        $node = $this->nodes['B']; // proposer for height 1, round 0
        $this->set($node['driver'], 'started', microtime(true) - 61);
        $node['driver']->tick();
        $proposals = array_filter($node['journal']->messages(1), fn($m) => $m->kind === 'proposal');
        self::assertCount(0, $proposals, 'A round timer must not substitute for the new-height cadence clock');
        self::assertSame(1, (new \ReflectionProperty($node['driver'], 'round'))->getValue($node['driver']), 'Pacemaker must still advance independently');
    }

    public function testExpiredHeightGateAllowsProposalInAFreshRoundAndResetsAfterFinality(): void
    {
        foreach ($this->nodes as $node) $this->set($node['driver'], 'heightStarted', microtime(true) - 61);
        // Deliver actual signed journal messages through the real driver verifier.
        $delivered = [];
        for ($step = 0; $step < 20; $step++) {
            foreach ($this->nodes as $node) $node['driver']->tick();
            foreach ($this->nodes as $sender => $node) {
                foreach ($node['journal']->messages(1) as $message) {
                    foreach ($this->nodes as $recipient => $peer) {
                        $key = $sender . ':' . $recipient . ':' . $message->round . ':' . $message->kind;
                        if ($sender === $recipient || isset($delivered[$key]) || $peer['chain']->tip->getHeight() >= 1) continue;
                        $peer['driver']->accept($message);
                        $delivered[$key] = true;
                    }
                }
            }
        }
        foreach ($this->nodes as $node) {
            self::assertSame(1, $node['chain']->tip->getHeight(), 'Three real validators must finalize after the height cadence expires');
            self::assertNotEmpty($node['chain']->files->loadCertificate(1));
            self::assertCount(0, array_filter($node['journal']->messages(2), fn($m) => $m->kind === 'proposal'), 'Next height must wait for a new cadence interval');
        }
    }
}
