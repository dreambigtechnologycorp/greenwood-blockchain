<?php
declare(strict_types=1);
namespace Greenwood\Tests\Rpc;

use Greenwood\Contracts\{ContractReceipt, ContractTransactionEvent};
use Greenwood\Genesis\Genesis;
use Greenwood\Node\Chain;
use Greenwood\Persistence\{BlockFilesystem, StateSqlite};
use Greenwood\Rpc\HttpApi;
use Greenwood\Tests\Fixtures\ContractFixture as F;
use Greenwood\Transaction\Transfer;
use PHPUnit\Framework\TestCase;

final class ContractReceiptTest extends TestCase
{
    private string $dir;
    private Genesis $genesis;
    private Chain $chain;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/gw-receipt-'.bin2hex(random_bytes(6));
        $this->genesis = new Genesis(F::CHAIN);
        $this->genesis->addValidator('A', F::key(), 1)->addInitialAccount('sender', F::key(), 1000000);
        $this->chain = $this->reload();
    }
    private function reload(): Chain
    {
        return new Chain($this->genesis, new BlockFilesystem($this->dir.'/blocks'), new StateSqlite($this->dir.'/state.sqlite'));
    }
    private function add(array $txs = []): void
    {
        $this->chain->finalize($this->chain->build($txs, 0, F::address()));
    }
    private function api(?string $pending = null): HttpApi
    {
        return new HttpApi($this->chain, fn() => throw new \LogicException('Submission forbidden'),
            fn($hash) => $hash === $pending, fn() => 0, fn() => 'full_node', fn() => true, fn() => 0);
    }
    private function read(string $hex, int $status = 200, ?string $code = null, ?string $pending = null): array
    {
        $r = $this->api($pending)->handle('GET', '/v1/transactions/'.$hex.'/receipt', '');
        self::assertSame($status, $r->status, $r->body);
        $body = json_decode($r->body, true, flags: JSON_THROW_ON_ERROR);
        if ($code !== null) self::assertSame($code, $body['error']['code']);
        return $body;
    }
    private function db(): \PDO { return new \PDO('sqlite:'.$this->dir.'/state.sqlite'); }
    private function replaceReceipts(array $receipts): void
    {
        (new \ReflectionProperty($this->chain->state, 'receipts'))->setValue($this->chain->state, $receipts);
    }
    protected function tearDown(): void
    {
        $this->chain->index->close();
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        rmdir($this->dir);
    }
    public function testHistoricalDeployAndCallSurviveRestartAndReadsDoNotWrite(): void
    {
        $deploy = F::deploy($this->chain->state, 'reference_value'); $this->add([$deploy]);
        $call = F::call($this->chain->state, 'reference_value', 'balance'); $this->add([$call]);
        $blockHash = bin2hex($this->chain->tip->getHash()); $this->add();
        $state = serialize($this->chain->state);
        $db = $this->db();
        $version = $db->query('PRAGMA data_version')->fetchColumn();
        $rows = $db->query('SELECT * FROM contract_receipts ORDER BY position')->fetchAll();
        foreach ([$deploy, $call] as $i => $tx) {
            $body = $this->read(bin2hex($tx->getHash()));
            self::assertSame($i + 1, $body['included_height']);
            self::assertSame(3, $body['finalized_height']);
            self::assertSame('derived_finalized_execution', $body['provenance']);
            self::assertSame($this->chain->state->getContractReceipt($tx->getHash())->toArray(), $body['receipt']);
            self::assertSame('SUCCESS', $body['receipt']['status']);
            self::assertSame(bin2hex($tx->getHash()), $body['receipt']['transaction_hash']);
        }
        self::assertSame($blockHash, $body['block_hash']);
        self::assertSame($state, serialize($this->chain->state));
        self::assertSame($version, $db->query('PRAGMA data_version')->fetchColumn());
        self::assertSame($rows, $db->query('SELECT * FROM contract_receipts ORDER BY position')->fetchAll());
        $this->chain->index->close(); $this->chain = $this->reload();
        self::assertSame($body, $this->read(bin2hex($call->getHash())));
    }
    public function testTypedAbsenceAndNonContractErrors(): void
    {
        $this->read('bad', 400, 'INVALID_REQUEST');
        $this->read(str_repeat('a', 64), 404, 'TRANSACTION_NOT_FOUND');
        $this->read(str_repeat('b', 64), 409, 'TRANSACTION_NOT_FINALIZED', str_repeat('b', 64));
        $tx = (new Transfer(F::address(), 1))->createEnvelope(F::CHAIN, F::address(), 0, F::key());
        $this->add([$tx]);
        $this->read(bin2hex($tx->getHash()), 409, 'NOT_CONTRACT_TRANSACTION');
    }
    public function testIncompleteAndUnanchoredIndexFailClosedWithoutRepair(): void
    {
        $tx = F::deploy($this->chain->state, 'reference_value'); $this->add([$tx]);
        foreach (['height=0', "block_hash='wrong'"] as $damage) {
            $db = $this->db(); $db->exec('UPDATE transaction_index_progress SET '.$damage);
            $before = $db->query('SELECT * FROM transaction_index_progress')->fetchAll();
            $this->read(bin2hex($tx->getHash()), 503, 'RECEIPT_INDEX_UNAVAILABLE');
            self::assertSame($before, $db->query('SELECT * FROM transaction_index_progress')->fetchAll());
            $this->chain->index->close(); $this->chain = $this->reload();
        }
    }
    public function testBadCanonicalLocatorsFailClosed(): void
    {
        $tx = F::deploy($this->chain->state, 'reference_value'); $this->add([$tx]);
        foreach (['position=99', "block_hash='wrong'", 'height=99'] as $damage) {
            $db = $this->db(); $db->exec('UPDATE transactions SET '.$damage);
            $before = $db->query('SELECT * FROM transactions')->fetchAll();
            $this->read(bin2hex($tx->getHash()), 500, 'RECEIPT_INCONSISTENT');
            self::assertSame($before, $db->query('SELECT * FROM transactions')->fetchAll());
            $this->chain->index->close(); $this->chain = $this->reload();
        }
    }
    public function testMissingAndInvalidDerivedEvidenceIsNeverInferred(): void
    {
        $tx = F::deploy($this->chain->state, 'reference_value'); $this->add([$tx]);
        $r = $this->chain->state->getContractReceipt($tx->getHash());
        $this->replaceReceipts([]);
        $this->read(bin2hex($tx->getHash()), 503, 'RECEIPT_NOT_FOUND');
        self::assertSame([], $this->chain->state->getContractReceipts());
        foreach ([
            new ContractReceipt('FAILURE', 1, '', [], $r->contractId, $r->transactionHash),
            new ContractReceipt('SUCCESS', -1, '', [], $r->contractId, $r->transactionHash),
            new ContractReceipt('SUCCESS', 1, '', [], str_repeat('x', 32), $r->transactionHash),
            new ContractReceipt('SUCCESS', 1, '', [], $r->contractId, str_repeat('x', 32)),
            new ContractReceipt('SUCCESS', 1, '', [new ContractTransactionEvent($r->contractId, '', '', $r->transactionHash, 1)], $r->contractId, $r->transactionHash),
            new ContractReceipt('SUCCESS', 1, '', [new ContractTransactionEvent(str_repeat('x', 32), '', '', $r->transactionHash, 0)], $r->contractId, $r->transactionHash),
        ] as $bad) {
            $this->replaceReceipts([$tx->getHash() => $bad]);
            $this->read(bin2hex($tx->getHash()), 500, 'RECEIPT_INCONSISTENT');
            self::assertSame($bad, $this->chain->state->getContractReceipt($tx->getHash()));
        }
        // DTO serialization check with explicit binary event data, independent of execution fixture.
        $events = [new ContractTransactionEvent($r->contractId, "\xff", "\x00", $r->transactionHash, 0),
            new ContractTransactionEvent($r->contractId, '', 'second', $r->transactionHash, 1)];
        $receipt = new ContractReceipt('SUCCESS', 55, "\x01\x00\x00\x00", $events, $r->contractId, $r->transactionHash);
        $this->replaceReceipts([$tx->getHash() => $receipt]);
        self::assertSame($receipt->toArray(), $this->read(bin2hex($tx->getHash()))['receipt']);
    }
    public function testExecutedStorageEventAndReturnBytesArePreserved(): void
    {
        $deploy = F::deploy($this->chain->state, 'reference_storage'); $this->add([$deploy]);
        $input = \Greenwood\Codec\Canonical::encodeBytes('key').\Greenwood\Codec\Canonical::encodeBytes('after');
        $set = F::call($this->chain->state, 'reference_storage', 'set', $input); $this->add([$set]);
        $receipt = $this->read(bin2hex($set->getHash()))['receipt'];
        self::assertSame([['contract_id' => $receipt['contract_id'], 'topic' => bin2hex('set'),
            'data' => bin2hex($input), 'transaction_hash' => bin2hex($set->getHash()), 'event_index' => 0]], $receipt['events']);
        $get = F::call($this->chain->state, 'reference_storage', 'get', \Greenwood\Codec\Canonical::encodeBytes('key'));
        $this->add([$get]);
        self::assertSame('6166746572', $this->read(bin2hex($get->getHash()))['receipt']['return_data']);
    }
    public function testRejectedExecutionDoesNotCreateFinalizedFailureReceipt(): void
    {
        $tx = F::deploy($this->chain->state, 'reference_value'); $this->add([$tx]);
        $call = F::call($this->chain->state, 'reference_value', 'missing_method');
        $before = serialize($this->chain->state);
        try { $this->chain->build([$call], 0, F::address()); self::fail('Expected execution rejection'); }
        catch (\Greenwood\Contracts\ContractTransactionException) {}
        self::assertSame($before, serialize($this->chain->state));
        self::assertNull($this->chain->state->getContractReceipt($call->getHash()));
        $this->read(bin2hex($call->getHash()), 404, 'TRANSACTION_NOT_FOUND');
    }
}
