<?php

declare(strict_types=1);

namespace Greenwood\Persistence;

use PDO;
use PDOException;
use Greenwood\Crypto\ProtocolHash;

/**
 * StateSqlite provides SQLite-based state persistence.
 */
class StateSqlite
{
    private ?PDO $pdo = null;
    private string $dbPath;
    private bool $isOpen = false;

    public function __construct(string $dbPath)
    {
        $this->dbPath = $dbPath;
    }

    private function open(): void
    {
        if ($this->isOpen || $this->pdo !== null) {
            return;
        }

        // Ensure directory exists
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA synchronous=FULL');
        $this->createSchema();
        $this->isOpen = true;
    }

    private function createSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS accounts (
                address BINARY(32) PRIMARY KEY,
                balance INTEGER NOT NULL DEFAULT 0,
                nonce INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )
        ");
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS chain_state (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                chain_height INTEGER NOT NULL DEFAULT 0,
                finalized_block_hash BINARY(32),
                finalized_state_root BINARY(32),
                updated_at INTEGER NOT NULL
            )
        ");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS validators (id TEXT PRIMARY KEY, public_key BLOB NOT NULL, power INTEGER NOT NULL)");
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS contract_code (contract_id BLOB PRIMARY KEY, wasm BLOB NOT NULL, abi_version INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS contract_storage (contract_id BLOB NOT NULL, storage_key BLOB NOT NULL, value BLOB NOT NULL, PRIMARY KEY(contract_id, storage_key))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS contract_balances (contract_id BLOB PRIMARY KEY, balance INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS contract_receipts (transaction_hash BLOB PRIMARY KEY, position INTEGER NOT NULL UNIQUE, receipt_json TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS compute_records (namespace TEXT NOT NULL, request_id BLOB NOT NULL, value BLOB NOT NULL, PRIMARY KEY(namespace, request_id))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS provider_eligibility (trust_class INTEGER NOT NULL, public_key BLOB NOT NULL, PRIMARY KEY(trust_class, public_key))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS transactions (tx_hash BLOB PRIMARY KEY, height INTEGER NOT NULL, block_hash BLOB NOT NULL, position INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS transaction_index_progress (id INTEGER PRIMARY KEY CHECK(id=1), height INTEGER NOT NULL, block_hash BLOB NOT NULL, genesis_hash BLOB NOT NULL, transaction_count INTEGER NOT NULL)');
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_balance ON accounts(balance)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_height ON chain_state(chain_height)");
    }

    public function upsertAccount(string $address, int $balance, int $nonce): self
    {
        $this->open();
        $now = time();
        $stmt = $this->pdo->prepare("
            INSERT INTO accounts (address, balance, nonce, created_at, updated_at)
            VALUES (:address, :balance, :nonce, :now, :now)
            ON CONFLICT(address) DO UPDATE SET
                balance = :balance2,
                nonce = :nonce2,
                updated_at = :now2
        ");
        $stmt->execute([
            ':address' => $address,
            ':balance' => $balance,
            ':nonce' => $nonce,
            ':now' => $now,
            ':balance2' => $balance,
            ':nonce2' => $nonce,
            ':now2' => $now
        ]);
        return $this;
    }

    /** Derived coverage is bound to canonical block identity, including empty blocks. */
    public function transactionIndexComplete(int $height, string $blockHash): bool
    {
        $progress = $this->transactionIndexProgress();
        return $progress !== null && (int)$progress['height'] === $height
            && $progress['block_hash'] === $blockHash;
    }

    public function transactionIndexProgress(): ?array
    {
        $this->open();
        return $this->pdo->query('SELECT * FROM transaction_index_progress WHERE id=1')->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getTransactionByHash(string $txHash): ?array
    {
        $this->open();
        $stmt = $this->pdo->prepare('SELECT height, block_hash, position FROM transactions WHERE tx_hash = ?');
        $stmt->execute([$txHash]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Run after canonical history verification, before serving RPC. A missing/legacy
     * watermark never proves coverage. Validate existing locators on startup, then
     * resume only the missing range. No canonical file or consensus state is changed.
     */
    public function synchronizeTransactionIndex(BlockFilesystem $files, \Greenwood\Block\Block $tip): void
    {
        $this->open();
        $genesis = $files->getBlockByHeight(0);
        if ($genesis === null) throw new \RuntimeException('Missing canonical genesis');
        $progress = $this->transactionIndexProgress();
        $valid = $progress !== null && $progress['genesis_hash'] === $genesis->getHash()
            && (int)$progress['height'] >= 0 && (int)$progress['height'] <= $tip->getHeight()
            && (int)$progress['transaction_count'] === (int)$this->pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
        if ($valid) {
            $anchor = $files->getBlockByHeight((int)$progress['height']);
            $valid = $anchor !== null && $anchor->getHash() === $progress['block_hash'];
        }
        if ($valid) {
            // Detect logically corrupt locators without rescanning empty history.
            $q = $this->pdo->query('SELECT * FROM transactions ORDER BY height, position');
            $block = null;
            while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                $height = (int)$row['height'];
                if ($height < 1 || $height > (int)$progress['height']) { $valid = false; break; }
                if ($block === null || $block->getHeight() !== $height) $block = $files->getBlockByHeight($height);
                $tx = $block?->getTransactions()[(int)$row['position']] ?? null;
                if ($block === null || $row['block_hash'] !== $block->getHash()
                    || $tx === null || $tx->getHash() !== $row['tx_hash']) { $valid = false; break; }
            }
            $q->closeCursor();
        }
        if (!$valid) {
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $this->pdo->exec('DELETE FROM transactions');
                $this->pdo->exec('DELETE FROM transaction_index_progress');
                $q = $this->pdo->prepare('INSERT INTO transaction_index_progress VALUES (1,0,?,?,0)');
                $q->execute([$genesis->getHash(), $genesis->getHash()]);
                $this->pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                $this->pdo->exec('ROLLBACK'); throw $e;
            }
            $progress = $this->transactionIndexProgress();
        }
        for ($h = (int)$progress['height'] + 1; $h <= $tip->getHeight(); $h++) {
            $block = $files->getBlockByHeight($h);
            if ($block === null) throw new \RuntimeException('Missing canonical block during transaction backfill');
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $this->extendTransactionIndex($block);
                $this->pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                $this->pdo->exec('ROLLBACK'); throw $e;
            }
        }
        if (!$this->transactionIndexComplete($tip->getHeight(), $tip->getHash())) {
            throw new \RuntimeException('Transaction index does not cover canonical tip');
        }
    }

    /** Called only inside the same SQLite transaction as its coverage update. */
    private function extendTransactionIndex(\Greenwood\Block\Block $block): void
    {
        $progress = $this->transactionIndexProgress();
        if ($this->transactionIndexComplete($block->getHeight(), $block->getHash())) return;
        if ($progress === null || (int)$progress['height'] !== $block->getHeight() - 1
            || $progress['block_hash'] !== $block->getHeader()->getParentHash()) {
            throw new \RuntimeException('Transaction index must cover the preceding canonical block');
        }
        $q = $this->pdo->prepare('INSERT INTO transactions (tx_hash,height,block_hash,position) VALUES (?,?,?,?)');
        foreach ($block->getTransactions() as $position => $tx) {
            $q->execute([$tx->getHash(), $block->getHeight(), $block->getHash(), $position]);
        }
        $q = $this->pdo->prepare('UPDATE transaction_index_progress SET height=?, block_hash=?, transaction_count=transaction_count+? WHERE id=1');
        $q->execute([$block->getHeight(), $block->getHash(), count($block->getTransactions())]);
    }

    public function getAccount(string $address): ?array
    {
        $this->open();
        $stmt = $this->pdo->prepare("SELECT * FROM accounts WHERE address = :address");
        $stmt->execute([':address' => $address]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }

    public function getBalance(string $address): int
    {
        $account = $this->getAccount($address);
        return $account ? (int)$account['balance'] : 0;
    }

    public function getNonce(string $address): int
    {
        $account = $this->getAccount($address);
        return $account ? (int)$account['nonce'] : 0;
    }

    public function getAllAccounts(): array
    {
        $this->open();
        $stmt = $this->pdo->query("SELECT * FROM accounts");
        $accounts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $accounts[] = $row;
        }
        return $accounts;
    }

    public function loadState(): \Greenwood\State\State
    {
        $this->open();
        $state = new \Greenwood\State\State();
        foreach ($this->getAllAccounts() as $a) {
            $state->setAccountBalance($a['address'], (int)$a['balance'])->setAccountNonce($a['address'], (int)$a['nonce']);
        }
        $validators = [];
        foreach ($this->pdo->query('SELECT * FROM validators') as $v) {
            $validators[$v['id']] = ['publicKey' => $v['public_key'],'votingPower' => (int)$v['power']];
        }
        foreach ($this->pdo->query('SELECT * FROM contract_code') as $row) {
            if ((int)$row['abi_version'] !== 1) {
                throw new \RuntimeException('Unsupported derived contract ABI');
            }
            $state->setContractCode($row['contract_id'], $row['wasm'], $row['contract_id']);
        }
        foreach ($this->pdo->query('SELECT * FROM contract_storage') as $row) {
            $state->setContractStorage($row['contract_id'], $row['storage_key'], $row['value']);
        }
        foreach ($this->pdo->query('SELECT * FROM contract_balances') as $row) {
            $state->setContractBalance($row['contract_id'], (int)$row['balance']);
        }
        foreach ($this->pdo->query('SELECT receipt_json FROM contract_receipts ORDER BY position') as $row) {
            $state->indexContractReceipt(\Greenwood\Contracts\ContractReceipt::fromArray(json_decode($row['receipt_json'], true, 512, JSON_THROW_ON_ERROR)));
        }
        $trusted = [];
        $sovereign = [];
        foreach ($this->pdo->query('SELECT * FROM provider_eligibility') as $row) {
            if ((int)$row['trust_class'] === 1) {
                $trusted[] = $row['public_key'];
            } elseif ((int)$row['trust_class'] === 2) {
                $sovereign[] = $row['public_key'];
            } else {
                throw new \RuntimeException('Invalid derived eligibility');
            }
        }
        $state->setProviderEligibility(new \Greenwood\Compute\Verification\GenesisProviderEligibility($trusted, $sovereign));
        $records = $this->pdo->query('SELECT namespace, request_id, value FROM compute_records')->fetchAll(PDO::FETCH_NUM);
        $state->restoreDerivedComputeRecords($records)->setValidators($validators)->recomputeStateRoot();
        $expected = $this->getFinalizedStateRoot();
        if ($expected !== null && $expected !== $state->getStateRoot()) {
            throw new \RuntimeException('Derived state root mismatch');
        }
        return $state;
    }
    public function computeStateRoot(): string
    {
        return $this->loadState()->getStateRoot();
    }
    public function rebuild(\Greenwood\State\State $state, \Greenwood\Block\Block $block, array $validators): void
    {
        try {
            $this->commitState($state, $block, $validators, false);
        } catch (\PDOException $e) {
            $code = (int)($e->errorInfo[1] ?? 0) & 0xff;
            if (!in_array($code, [11,26], true)) {
                throw $e;
            } // Only corrupt/not-a-database; never hide I/O failures.
            $this->close();
            $suffix = '.corrupt.'.bin2hex(random_bytes(6));
            foreach (['','-journal','-wal','-shm'] as $sidecar) {
                $path = $this->dbPath.$sidecar;
                if (is_file($path) && !rename($path, $path.$suffix)) {
                    throw new \RuntimeException('Cannot quarantine corrupt derived index');
                }
            }
            $this->commitState($state, $block, $validators, false);
        }
    }

    public function commitState(\Greenwood\State\State $state, \Greenwood\Block\Block $block, array $validators, bool $extendIndex = true): void
    {
        $this->open();
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->pdo->exec('DELETE FROM accounts');
            $this->pdo->exec('DELETE FROM validators');
            foreach ($state->getAccounts() as $a) {
                $this->upsertAccount($a->getAddress(), $a->getBalance(), $a->getNonce());
            }
            $q = $this->pdo->prepare('INSERT INTO validators VALUES (?, ?, ?)');
            foreach ($validators as $id => $v) {
                $q->execute([$id,$v['publicKey'],$v['votingPower']]);
            }
            foreach (['contract_code','contract_storage','contract_balances','contract_receipts'] as $table) {
                $this->pdo->exec('DELETE FROM '.$table);
            }
            $q = $this->pdo->prepare('INSERT INTO contract_code VALUES (?,?,?)');
            foreach ($state->getContractCodes() as $id => $code) {
                $q->execute([$id,$code['wasm'],$code['abi_version']]);
            }
            $q = $this->pdo->prepare('INSERT INTO contract_storage VALUES (?,?,?)');
            foreach ($state->getContractStorageRecords() as $record) {
                $q->execute($record);
            }
            $q = $this->pdo->prepare('INSERT INTO contract_balances VALUES (?,?)');
            foreach ($state->getContractBalances() as $id => $balance) {
                $q->execute([$id,$balance]);
            }
            $q = $this->pdo->prepare('INSERT INTO contract_receipts VALUES (?,?,?)');
            $position = 0;
            foreach ($state->getContractReceipts() as $hash => $receipt) {
                $q->execute([$hash,$position++,json_encode($receipt->toArray(), JSON_THROW_ON_ERROR)]);
            }
            if ($extendIndex) {
                $this->extendTransactionIndex($block);
            }
            $this->pdo->exec('DELETE FROM compute_records');
            $q = $this->pdo->prepare('INSERT INTO compute_records VALUES (?,?,?)');
            foreach ($state->getComputeState()->logicalRecords() as $record) {
                $q->execute($record);
            }
            $this->pdo->exec('DELETE FROM provider_eligibility');
            $q = $this->pdo->prepare('INSERT OR IGNORE INTO provider_eligibility VALUES (?,?)');
            $eligibility = $state->getProviderEligibility();
            foreach ([1 => $eligibility->trustedKeys, 2 => $eligibility->sovereignKeys] as $class => $keys) {
                foreach ($keys as $key) {
                    $q->execute([$class, $key]);
                }
            }
            $this->setChainHeight($block->getHeight());
            $this->setFinalizedBlockHash(bin2hex($block->getHash()));
            $this->setFinalizedStateRoot($state->getStateRoot());
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    // Chain state methods
    public function setChainHeight(int $height): self
    {
        $this->open();
        $now = time();
        $stmt = $this->pdo->prepare("
            INSERT INTO chain_state (id, chain_height, updated_at)
            VALUES (1, :height, :now)
            ON CONFLICT(id) DO UPDATE SET
                chain_height = :height2,
                updated_at = :now2
        ");
        $stmt->execute([
            ':height' => $height,
            ':now' => $now,
            ':height2' => $height,
            ':now2' => $now
        ]);
        return $this;
    }

    public function getChainHeight(): int
    {
        $this->open();
        $stmt = $this->pdo->query("SELECT chain_height FROM chain_state WHERE id = 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['chain_height'] : 0;
    }

    public function setFinalizedBlockHash(string $hash): self
    {
        $this->open();
        $now = time();
        $stmt = $this->pdo->prepare("
            INSERT INTO chain_state (id, finalized_block_hash, updated_at)
            VALUES (1, :hash, :now)
            ON CONFLICT(id) DO UPDATE SET
                finalized_block_hash = :hash2,
                updated_at = :now2
        ");
        $stmt->execute([
            ':hash' => hex2bin($hash),
            ':now' => $now,
            ':hash2' => hex2bin($hash),
            ':now2' => $now
        ]);
        return $this;
    }

    public function getFinalizedBlockHash(): ?string
    {
        $this->open();
        $stmt = $this->pdo->query("SELECT finalized_block_hash FROM chain_state WHERE id = 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? bin2hex($row['finalized_block_hash']) : null;
    }

    public function setFinalizedStateRoot(string $root): self
    {
        $this->open();
        $now = time();
        $stmt = $this->pdo->prepare("
            INSERT INTO chain_state (id, finalized_state_root, updated_at)
            VALUES (1, :root, :now)
            ON CONFLICT(id) DO UPDATE SET
                finalized_state_root = :root2,
                updated_at = :now2
        ");
        $stmt->execute([
            ':root' => $root,
            ':now' => $now,
            ':root2' => $root,
            ':now2' => $now
        ]);
        return $this;
    }

    public function getFinalizedStateRoot(): ?string
    {
        $this->open();
        $stmt = $this->pdo->query("SELECT finalized_state_root FROM chain_state WHERE id = 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['finalized_state_root'] : null;
    }

    // Cleanup
    public function close(): void
    {
        if ($this->pdo !== null) {
            $this->pdo = null;
            $this->isOpen = false;
            $this->isOpen = false;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
