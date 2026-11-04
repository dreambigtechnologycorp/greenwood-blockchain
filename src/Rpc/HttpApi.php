<?php

declare(strict_types=1);

namespace Greenwood\Rpc;

use Greenwood\Block\Block;
use Greenwood\Compute\Codec\ComputeReceiptV2Codec;
use Greenwood\Compute\Protocol\{ComputeAssign, ComputeReceiptV2, ComputeStatus, ProviderCapability, TrustClass};
use Greenwood\Node\Chain;
use Greenwood\Node\ChainConfig;
use Greenwood\Contracts\{ContractId, ContractTransactionEvent, ContractTransactionExecutor};
use Greenwood\Transaction\{CallContract, DeployContract, Envelope};

/**
 * Pass 5 versioned public API (/v1). Pure adapter: handlers delegate to
 * Chain/State/ComputeState and the existing transaction admission closure;
 * no consensus, scheduling or receipt logic lives here.
 *
 * Route auth classification (single source, see ROUTES):
 *   PUBLIC        — read-only /v1 routes, health/ready/metrics
 *   AUTHENTICATED — POST /v1/transactions (open on private test networks;
 *                   classified so a token check can be added in one place)
 *   OPERATOR_ONLY — served by a separate operator listener, never here.
 */
final class HttpApi
{
    public const VERSION = 'v1';
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    public const ROUTES = [
        'GET /v1/chain/status' => 'PUBLIC',
        'GET /v1/chain/finality' => 'PUBLIC',
        'GET /v1/chain/validators' => 'PUBLIC',
        'GET /v1/chain/state-root' => 'PUBLIC',
        'GET /v1/blocks/{height}' => 'PUBLIC',
        'GET /v1/blocks/hash/{hash}' => 'PUBLIC',
        'GET /v1/accounts/{id}' => 'PUBLIC',
        'GET /v1/accounts/{id}/balance' => 'PUBLIC',
        'GET /v1/accounts/{id}/nonce' => 'PUBLIC',
        'POST /v1/transactions' => 'AUTHENTICATED',
        'GET /v1/transactions/{hash}' => 'PUBLIC',
        'GET /v1/transactions/{hash}/status' => 'PUBLIC',
        'GET /v1/transactions/{hash}/receipt' => 'PUBLIC',
        'GET /v1/compute/requests/{id}' => 'PUBLIC',
        'GET /v1/compute/requests/{id}/history' => 'PUBLIC',
        'GET /v1/compute/requests/{id}/receipt' => 'PUBLIC',
        'GET /v1/compute/receipts/{id}' => 'PUBLIC',
        'GET /v1/compute/providers' => 'PUBLIC',
        'GET /v1/compute/providers/{id}' => 'PUBLIC',
        'GET /v1/compute/providers/{id}/reservations' => 'PUBLIC',
        'GET /v1/health' => 'PUBLIC',
        'GET /v1/ready' => 'PUBLIC',
        'GET /v1/contracts/{id}' => 'PUBLIC',
        'POST /v1/contracts/{id}/call' => 'PUBLIC',
        'GET /ready' => 'PUBLIC',
        'GET /v1/metrics' => 'PUBLIC',
        'GET /metrics' => 'PUBLIC',
    ];

    /**
     * @param \Closure(string):string $admit existing transaction admission; returns hex tx hash
     * @param \Closure(string):bool   $pending whether a hex tx hash is in the mempool
     * @param \Closure():int          $pendingCount mempool size
     * @param \Closure():string       $role 'validator' or 'full_node'
     * @param \Closure():bool         $identityReady signing identity available (validator only)
     * @param \Closure():int          $round current consensus round
     */
    public function __construct(
        private readonly Chain $chain,
        private readonly \Closure $admit,
        private readonly \Closure $pending,
        private readonly \Closure $pendingCount,
        private readonly \Closure $role,
        private readonly \Closure $identityReady,
        private readonly \Closure $round,
        private readonly ?ChainConfig $config = null,
    ) {
    }

    public function handle(string $method, string $path, string $body): HttpResponse
    {
        try {
            return $this->route($method, $path, $body);
        } catch (ApiError $e) {
            return HttpResponse::error($e);
        } catch (\InvalidArgumentException $e) {
            return HttpResponse::error(ApiError::invalidRequest($e->getMessage()));
        } catch (\Throwable) {
            return HttpResponse::error(ApiError::internal());
        }
    }

    private function route(string $method, string $path, string $body): HttpResponse
    {
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        // Reject control bytes, traversal and duplicate separators before any matching.
        if (!preg_match('~^[\x21-\x7e]*$~D', $route) || str_contains($route, '..') || str_contains($route, '//')) {
            throw ApiError::invalidRequest('Malformed request path');
        }
        $params = $this->query($query);

        if ($method === 'GET' && $route === '/v1/health') {
            return HttpResponse::json(['ok' => true]);
        }
        if ($method === 'GET' && ($route === '/v1/ready' || $route === '/ready')) {
            return $this->ready();
        }
        if ($method === 'GET' && ($route === '/v1/metrics' || $route === '/metrics')) {
            return $this->metrics();
        }

        if ($method === 'GET' && $route === '/v1/chain/status') {
            return HttpResponse::json($this->chainStatus());
        }
        if ($method === 'GET' && $route === '/v1/chain/finality') {
            return HttpResponse::json($this->finality());
        }
        if ($method === 'GET' && $route === '/v1/chain/validators') {
            return HttpResponse::json(['validators' => $this->validators()]);
        }
        if ($method === 'GET' && $route === '/v1/chain/state-root') {
            return HttpResponse::json(['state_root' => bin2hex($this->chain->state->getStateRoot()), 'finalized_height' => $this->chain->tip->getHeight()]);
        }
        if ($method === 'GET' && preg_match('~^/v1/blocks/(\d{1,10})$~D', $route, $m)) {
            return HttpResponse::json($this->blockDto($this->blockAt((int) $m[1])));
        }
        if ($method === 'GET' && preg_match('~^/v1/blocks/hash/([0-9a-f]{64})$~D', $route, $m)) {
            return HttpResponse::json($this->blockDto($this->blockByHash($m[1])));
        }
        if ($method === 'GET' && preg_match('~^/v1/accounts/([0-9a-f]{64})(/balance|/nonce)?$~D', $route, $m)) {
            return HttpResponse::json($this->account($m[1], $m[2] ?? ''));
        }
        if ($method === 'GET' && preg_match('~^/v1/contracts/([0-9a-f]{64})$~D', $route, $m)) {
            return HttpResponse::json($this->contract($m[1]));
        }
        if ($method === 'POST' && preg_match('~^/v1/contracts/([0-9a-f]{64})/call$~D', $route, $m)) {
            return $this->contractCall($m[1], $body);
        }
        if ($method === 'POST' && $route === '/v1/transactions') {
            return $this->submitTransaction($body);
        }
        if ($method === 'GET' && preg_match('~^/v1/transactions/([0-9a-f]{64})/receipt$~D', $route, $m)) {
            return HttpResponse::json($this->transactionReceipt($m[1]));
        }
        if ($method === 'GET' && preg_match('~^/v1/transactions/([0-9a-f]{64})(/status)?$~D', $route, $m)) {
            return HttpResponse::json($this->transaction($m[1], ($m[2] ?? '') === '/status'));
        }
        if ($method === 'GET' && preg_match('~^/v1/compute/requests/([0-9a-f]{64})(/history|/receipt)?$~D', $route, $m)) {
            $suffix = $m[2] ?? '';
            if ($suffix === '/history') {
                return HttpResponse::json($this->computeHistory($m[1]));
            }
            if ($suffix === '/receipt') {
                return HttpResponse::json($this->computeReceiptByRequest($m[1]));
            }
            return HttpResponse::json($this->computeRequest($m[1]));
        }
        if ($method === 'GET' && preg_match('~^/v1/compute/receipts/([0-9a-f]{64})$~D', $route, $m)) {
            return HttpResponse::json($this->computeReceiptById($m[1]));
        }
        if ($method === 'GET' && $route === '/v1/compute/providers') {
            return HttpResponse::json($this->providers($params));
        }
        if ($method === 'GET' && preg_match('~^/v1/compute/providers/([0-9a-f]{64})(/reservations)?$~D', $route, $m)) {
            if (($m[2] ?? '') === '/reservations') {
                return HttpResponse::json($this->reservations($m[1]));
            }
            return HttpResponse::json($this->provider($m[1]));
        }
        // A resource-shaped path with a malformed identifier is a 400, not a 404.
        if (preg_match('~^/v1/(?:accounts|contracts|transactions|blocks/hash|compute/(?:requests|receipts|providers))/([^/]+)~D', $route, $m)
            && !preg_match('~^[0-9a-f]{64}$~D', $m[1])) {
            throw ApiError::invalidRequest('Expected a 64-character lowercase hex identifier');
        }
        throw ApiError::notFound('NOT_FOUND', 'Unknown route');
    }

    private function query(string $query): array
    {
        if ($query === '') {
            return [];
        }
        parse_str($query, $params);
        foreach ($params as $name => $value) {
            if (!is_string($value) || !in_array($name, ['cursor', 'limit'], true)) {
                throw ApiError::invalidRequest('Unsupported query parameter', ['parameter' => (string) $name]);
            }
        }
        if (isset($params['limit'])
            && (!preg_match('/^\d{1,3}$/D', $params['limit']) || (int) $params['limit'] < 1 || (int) $params['limit'] > self::MAX_LIMIT)) {
            throw ApiError::invalidRequest('limit must be 1..' . self::MAX_LIMIT);
        }
        if (isset($params['cursor']) && !preg_match('/^[0-9a-f]{64}$/D', $params['cursor'])) {
            throw ApiError::invalidRequest('cursor must be a 64-character lowercase hex identifier');
        }
        return $params;
    }

    private static function hexId(string $hex): string
    {
        if (!preg_match('/^[0-9a-f]{64}$/D', $hex)) {
            throw ApiError::invalidRequest('Expected a 64-character lowercase hex identifier');
        }
        return hex2bin($hex);
    }

    /** u64 values are serialized as decimal strings (JSON/JS safe-integer boundary). */
    private static function u64(int $value): string
    {
        if ($value < 0) {
            throw ApiError::internal();
        }
        return (string) $value;
    }


    // ---- chain -------------------------------------------------------------

    private function blockAt(int $height): Block
    {
        $block = $this->chain->files->getBlockByHeight($height);
        if ($block === null) {
            throw ApiError::notFound('BLOCK_NOT_FOUND', 'Block does not exist', ['height' => $height]);
        }
        return $block;
    }

    private function blockByHash(string $hex): Block
    {
        $target = self::hexId($hex);
        for ($h = 0; $h <= $this->chain->tip->getHeight(); $h++) {
            $block = $this->chain->files->getBlockByHeight($h);
            if ($block !== null && $block->getHash() === $target) {
                return $block;
            }
        }
        throw ApiError::notFound('BLOCK_NOT_FOUND', 'Block does not exist', ['block_hash' => $hex]);
    }

    private function chainStatus(): array
    {
        $config=$this->config;
        $accounts=$this->chain->state->getAccounts();
        $totalSupply=0;
        foreach ($accounts as $a) $totalSupply+=$a->getBalance();
        $validatorIds=array_keys($this->chain->genesis->getValidators());
        sort($validatorIds, SORT_STRING);
        return [
            'chain_id' => $this->chain->genesis->getChainId(),
            'api_version' => self::VERSION,
            'role' => ($this->role)(),
            'height' => $this->chain->tip->getHeight(),
            'finalized_height' => $this->chain->tip->getHeight(),
            'block_hash' => bin2hex($this->chain->tip->getHash()),
            'state_root' => bin2hex($this->chain->state->getStateRoot()),
            'validator_count' => count($validatorIds),
            'validator_ids' => $validatorIds,
            // V1 consensus/economic surface (see ChainConfig for the authority).
            'block_time_seconds' => $config?->blockTimeSeconds(),
            'supply' => [
                'hard_cap' => $config?->hardCap(),
                'total_supply' => self::u64($totalSupply),
                'account_count' => count($accounts),
                // Gaps (see deployment reconciliation report): reward release from
                // the Security & Infrastructure Reserve and per-transaction fee
                // disposition/burn are not yet runtime chain primitives.
                'emitted_rewards' => 0,
                'burned_fees' => 0,
            ],
            'economy' => $config?->toArray(),
            'syncing' => false,
        ];
    }

    private function finality(): array
    {
        $tip = $this->chain->tip;
        $height = $tip->getHeight();
        $cert = $height > 0 ? $this->chain->files->loadCertificate($height) : null;
        $votes = 0;
        $round = null;
        if ($cert !== null) {
            $certificate = \Greenwood\Consensus\Messages\Certificate::decode($cert);
            $votes = count($certificate->votes);
            $round = $certificate->votes[0]->round;
        }
        return [
            'height' => $height,
            'round' => $round,
            'block_hash' => bin2hex($tip->getHash()),
            'certificate_present' => $cert !== null,
            'precommit_count' => $votes,
            'validator_count' => count($this->chain->genesis->getValidators()),
        ];
    }

    private function validators(): array
    {
        $out = [];
        foreach ($this->chain->genesis->getValidators() as $id => $v) {
            $out[] = ['validator_id' => $id, 'public_key' => bin2hex($v['publicKey']), 'voting_power' => $v['votingPower'], 'active' => true];
        }
        return $out;
    }

    private function blockDto(Block $block): array
    {
        $h = $block->getHeader();
        $txs = [];
        foreach ($block->getTransactions() as $tx) {
            $txs[] = bin2hex($tx->getHash());
        }
        $cert = $block->getHeight() > 0 ? $this->chain->files->loadCertificate($block->getHeight()) : null;
        return [
            'height' => $h->getHeight(),
            'round' => $h->getRound(),
            'block_hash' => bin2hex($block->getHash()),
            'parent_hash' => bin2hex($h->getParentHash()),
            'state_root' => bin2hex($h->getStateRoot()),
            'previous_state_root' => bin2hex($h->getPreviousStateRoot()),
            'transactions_root' => bin2hex($h->getTransactionsRoot()),
            'proposer' => bin2hex($h->getProposer()),
            'transactions' => $txs,
            'finality_certificate_present' => $cert !== null,
        ];
    }

    // ---- accounts ------------------------------------------------------------

    private function account(string $hex, string $suffix): array
    {
        $id = self::hexId($hex);
        if (!$this->chain->state->hasAccount($id)) {
            throw ApiError::notFound('ACCOUNT_NOT_FOUND', 'Account does not exist', ['account_id' => $hex]);
        }
        $account = $this->chain->state->getAccount($id);
        return match ($suffix) {
            '/balance' => ['account_id' => $hex, 'balance' => self::u64($account->getBalance()), 'finalized_height' => $this->chain->tip->getHeight()],
            '/nonce' => ['account_id' => $hex, 'nonce' => $account->getNonce(), 'finalized_height' => $this->chain->tip->getHeight()],
            default => ['account_id' => $hex, 'balance' => self::u64($account->getBalance()), 'nonce' => $account->getNonce(), 'finalized_height' => $this->chain->tip->getHeight()],
        };
    }


    // ---- contracts ------------------------------------------------------------

    private function contract(string $hex): array
    {
        $id = self::hexId($hex);
        $code = $this->chain->state->getContractCode($id);
        if ($code === null) {
            throw ApiError::notFound('CONTRACT_NOT_FOUND', 'Contract does not exist', ['contract_id' => $hex]);
        }
        return [
            'contract_id' => $hex,
            'abi_version' => $code['abi_version'],
            'wasm_hash' => bin2hex($code['wasm_hash']),
            'balance' => self::u64($this->chain->state->getContractBalance($id)),
            'finalized_height' => $this->chain->tip->getHeight(),
        ];
    }

    private function contractCall(string $hex, string $body): HttpResponse
    {
        try {
            $input = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ApiError::invalidRequest('Invalid JSON body: ' . $e->getMessage());
        }
        if (!is_array($input) || !isset($input['method']) || !is_string($input['method'])) {
            throw ApiError::invalidRequest('Missing or invalid "method" field (expected string)');
        }
        if (!isset($input['calldata']) || !is_string($input['calldata'])) {
            throw ApiError::invalidRequest('Missing or invalid "calldata" field (expected base64 string)');
        }

        $method = $input['method'];
        $calldata = base64_decode($input['calldata'], true);
        if ($calldata === false) {
            throw ApiError::invalidRequest('Invalid base64 in "calldata" field');
        }

        $contractId = self::hexId($hex);
        $state = $this->chain->state; // finalized state

        try {
            $result = ContractTransactionExecutor::query($state, $contractId, $method, $calldata);
        } catch (\InvalidArgumentException $e) {
            // Contract not found or invalid args
            if (str_contains($e->getMessage(), 'Contract not found')) {
                throw ApiError::notFound('CONTRACT_NOT_FOUND', 'Contract does not exist', ['contract_id' => $hex]);
            }
            throw ApiError::invalidRequest($e->getMessage());
        } catch (\RuntimeException $e) {
            throw ApiError::semantic('CONTRACT_EXECUTION_ERROR', $e->getMessage());
        }

        $runtime = $result->runtime;
        $overlay = $result->overlay;
        $effects = $overlay->effects();

        if ($runtime->isSuccess()) {
            return HttpResponse::json([
                'contract_id' => $hex,
                'method' => $method,
                'status' => 'SUCCESS',
                'return_data' => bin2hex($effects['returnValue']),
                'fuel_used' => $runtime->getFuelConsumed() ?? 0,
                'finalized_height' => $this->chain->tip->getHeight(),
            ]);
        }

        // Execution failure — report status and fuel consumed
        $status = match ($runtime->getStatus()) {
            'trap' => 'TRAP',
            'fuel_exhausted' => 'OUT_OF_GAS',
            'missing_export' => 'MISSING_EXPORT',
            default => 'EXECUTION_ERROR',
        };
        return HttpResponse::json([
            'contract_id' => $hex,
            'method' => $method,
            'status' => $status,
            'error' => $runtime->getErrorMessage(),
            'fuel_used' => $runtime->getFuelConsumed() ?? 0,
            'finalized_height' => $this->chain->tip->getHeight(),
        ]);
    }

    private function submitTransaction(string $body): HttpResponse
    {
        try {
            $hash = ($this->admit)($body);
        } catch (\JsonException | \InvalidArgumentException $e) {
            throw ApiError::semantic('TRANSACTION_REJECTED', $e->getMessage());
        }
        return HttpResponse::json([
            'accepted' => true,
            'tx_hash' => $hash,
            // Transport acceptance is not finality; poll /v1/transactions/{hash}/status.
            'status' => 'PENDING',
        ]);
    }

    private function transactionIndexReady(): bool
    {
        return $this->chain->index->transactionIndexComplete($this->chain->tip->getHeight(), $this->chain->tip->getHash());
    }

    private function findTransaction(string $hex): ?array
    {
        $target = self::hexId($hex);
        if (!$this->transactionIndexReady()) {
            throw ApiError::notReady('Finalized transaction index is incomplete');
        }
        $row = $this->chain->index->getTransactionByHash($target);
        if ($row === null) return null; // A complete index miss is authoritative.
        $height = (int)$row['height'];
        if ($height < 1 || $height > $this->chain->tip->getHeight()) throw ApiError::internal();
        $block = $this->chain->files->getBlockByHeight($height);
        $tx = $block?->getTransactions()[(int)$row['position']] ?? null;
        if ($block === null || $block->getHash() !== $row['block_hash']
            || $tx === null || $tx->getHash() !== $target) {
            throw ApiError::internal('Invalid finalized transaction locator');
        }
        return [$tx, $block];
    }

    /** Synchronous event-loop read: no yield, replay, repair or persistence. */
    private function transactionReceipt(string $hex): array
    {
        $tip = $this->chain->tip;
        $state = $this->chain->state;
        $height = $tip->getHeight();
        $root = $state->getStateRoot();
        $details = ['tx_hash' => $hex];
        try {
            $found = $this->findTransaction($hex);
        } catch (ApiError $e) {
            throw new ApiError($e->apiCode === 'NOT_READY' ? 'RECEIPT_INDEX_UNAVAILABLE' : 'RECEIPT_INCONSISTENT',
                'Finalized receipt locator unavailable or inconsistent', $e->apiCode === 'NOT_READY' ? 503 : 500, $details);
        }
        if ($found === null) {
            if (($this->pending)($hex)) throw ApiError::conflict('TRANSACTION_NOT_FINALIZED', 'Transaction is not finalized', $details);
            throw ApiError::notFound('TRANSACTION_NOT_FOUND', 'Transaction does not exist', $details);
        }
        [$tx, $block] = $found;
        $details['included_height'] = $block->getHeight();
        if (!in_array($tx->getType(), [Envelope::TYPE_DEPLOY_CONTRACT, Envelope::TYPE_CALL_CONTRACT], true)) {
            throw ApiError::conflict('NOT_CONTRACT_TRANSACTION', 'Transaction is not a contract transaction', $details);
        }
        $receipt = $state->getContractReceipt($tx->getHash());
        if ($receipt === null) throw new ApiError('RECEIPT_NOT_FOUND', 'Finalized execution evidence is unavailable', 503, $details);
        try {
            $id = $tx->getType() === Envelope::TYPE_DEPLOY_CONTRACT
                ? ContractId::fromWasm(DeployContract::decode($tx->getPayload())->wasm)->bytes
                : CallContract::decode($tx->getPayload())->contractId;
            if ($receipt->transactionHash !== $tx->getHash() || $receipt->contractId !== $id
                || $receipt->status !== 'SUCCESS' || $receipt->gasUsed < 0 || !array_is_list($receipt->events)) {
                throw new \UnexpectedValueException('Invalid receipt');
            }
            foreach ($receipt->events as $index => $event) {
                if (!$event instanceof ContractTransactionEvent || $event->eventIndex !== $index
                    || $event->transactionHash !== $tx->getHash() || $event->contractId !== $id) {
                    throw new \UnexpectedValueException('Invalid receipt event');
                }
            }
            $display = $receipt->toArray();
        } catch (\Throwable) {
            throw new ApiError('RECEIPT_INCONSISTENT', 'Derived receipt does not match canonical transaction', 500, $details);
        }
        // Chain execution replaces state/tip synchronously. Detect a changed view defensively;
        // there is no handler-time snapshot/retry mechanism and no repair is attempted.
        if ($this->chain->tip !== $tip || $this->chain->state !== $state || $state->getStateRoot() !== $root
            || $height < $block->getHeight()) {
            throw new ApiError('RECEIPT_INDEX_UNAVAILABLE', 'Finalized observation changed during lookup', 503, $details);
        }
        return ['tx_hash' => $hex, 'included_height' => $block->getHeight(), 'block_hash' => bin2hex($block->getHash()),
            'finalized_height' => $height, 'receipt' => $display, 'provenance' => 'derived_finalized_execution'];
    }

    private function transaction(string $hex, bool $statusOnly): array
    {
        self::hexId($hex);
        $found = $this->findTransaction($hex);
        if ($found === null) {
            $status = ($this->pending)($hex) ? 'PENDING' : 'UNKNOWN';
            if ($statusOnly) {
                return ['tx_hash' => $hex, 'status' => $status];
            }
            if ($status === 'PENDING') {
                return ['tx_hash' => $hex, 'status' => 'PENDING', 'height' => null, 'block_hash' => null];
            }
            throw ApiError::notFound('TRANSACTION_NOT_FOUND', 'Transaction does not exist', ['tx_hash' => $hex]);
        }
        [$tx, $block] = $found;
        $base = [
            'tx_hash' => $hex,
            'status' => 'FINALIZED',
            'height' => $block->getHeight(),
            'block_hash' => bin2hex($block->getHash()),
        ];
        if ($statusOnly) {
            return $base;
        }
        return $base + [
            'type' => $tx->getType(),
            'sender' => bin2hex($tx->getSender()),
            'sender_public_key' => bin2hex($tx->getSenderPublicKey()),
            'nonce' => $tx->getNonce(),
            'finalized_height' => $this->chain->tip->getHeight(),
        ];
    }

    // ---- compute ---------------------------------------------------------------

    private function computeState(): \Greenwood\Compute\Protocol\ComputeState
    {
        return $this->chain->state->getComputeState();
    }

    private function computeRequest(string $hex): array
    {
        $id = self::hexId($hex);
        $compute = $this->computeState();
        try {
            $request = $compute->request($id);
        } catch (\InvalidArgumentException) {
            throw ApiError::notFound('COMPUTE_REQUEST_NOT_FOUND', 'Compute request does not exist', ['request_id' => $hex]);
        }
        $assignment = $compute->assignment($id);
        $receipt = $this->receiptFor($id);
        return [
            'request_id' => $hex,
            'requester' => bin2hex($request->getRequester()),
            'trust_class' => TrustClass::name($request->getTrustClass()),
            'workload_digest' => bin2hex($request->getWorkloadDigest()),
            'workload_ref' => $request->getWorkloadRef(),
            'input_digest' => bin2hex($request->getInputDigest()),
            'cpu_limit_millis' => $request->getCpuLimitMillis(),
            'memory_limit_bytes' => self::u64($request->getMemoryLimitBytes()),
            'pid_limit' => $request->getPidLimit(),
            'execution_timeout_ms' => self::u64($request->getExecutionTimeoutMs()),
            'max_payment' => self::u64($request->getMaxPayment()),
            'deadline_height' => $request->getDeadlineHeight(),
            'lifecycle' => $compute->lifecycle($id),
            'current_generation' => $assignment?->generation,
            'current_provider' => $assignment === null ? null : bin2hex($assignment->providerId),
            'assignment_deadline' => $assignment?->deadline,
            'terminal_receipt' => $receipt === null ? null : bin2hex($receipt->getId()),
            'finalized_height' => $this->chain->tip->getHeight(),
        ];
    }

    private function computeHistory(string $hex): array
    {
        $id = self::hexId($hex);
        $compute = $this->computeState();
        try {
            $compute->request($id);
        } catch (\InvalidArgumentException) {
            throw ApiError::notFound('COMPUTE_REQUEST_NOT_FOUND', 'Compute request does not exist', ['request_id' => $hex]);
        }
        $current = $compute->assignment($id);
        $receipt = $this->receiptFor($id);
        $terminalGeneration = $receipt?->generation;
        $entries = [];
        foreach ($this->chain->assignmentHistory($id) as $row) {
            $status = 'ASSIGNED';
            if ($terminalGeneration === $row['generation']) {
                $status = 'SETTLED';
            } elseif ($current !== null && $row['generation'] < $current->generation) {
                $status = 'REASSIGNED';
            } elseif ($terminalGeneration !== null) {
                $status = 'STALE';
            }
            $entries[] = ['generation' => $row['generation'], 'provider_id' => $row['provider_id'], 'height' => $row['height'], 'status' => $status];
        }
        return ['request_id' => $hex, 'lifecycle' => $compute->lifecycle($id), 'assignments' => $entries];
    }

    private function receiptFor(string $id): ?ComputeReceiptV2
    {
        $compute = $this->computeState();
        if (!$compute->terminal($id)) {
            return null;
        }
        foreach ($compute->logicalRecords() as [$namespace, $key, $value]) {
            if ($namespace !== 'compute_receipts' || $key !== $id) {
                continue;
            }
            $r = new \Greenwood\Codec\Reader($value, 2048);
            return ComputeReceiptV2Codec::decode($r->bytes(1100));
        }
        return null;
    }

    private function computeReceiptByRequest(string $hex): array
    {
        $id = self::hexId($hex);
        try {
            $this->computeState()->request($id);
        } catch (\InvalidArgumentException) {
            throw ApiError::notFound('COMPUTE_REQUEST_NOT_FOUND', 'Compute request does not exist', ['request_id' => $hex]);
        }
        $receipt = $this->receiptFor($id);
        if ($receipt === null) {
            throw ApiError::notFound('RECEIPT_NOT_FOUND', 'No terminal receipt for this request', ['request_id' => $hex]);
        }
        return $this->receiptDto($receipt);
    }

    private function computeReceiptById(string $hex): array
    {
        $target = self::hexId($hex);
        foreach ($this->computeState()->logicalRecords() as [$namespace, , $value]) {
            if ($namespace !== 'compute_receipts') {
                continue;
            }
            $r = new \Greenwood\Codec\Reader($value, 2048);
            $receipt = ComputeReceiptV2Codec::decode($r->bytes(1100));
            if ($receipt->getId() === $target) {
                return $this->receiptDto($receipt);
            }
        }
        throw ApiError::notFound('RECEIPT_NOT_FOUND', 'Receipt does not exist', ['receipt_id' => $hex]);
    }

    private function receiptDto(ComputeReceiptV2 $receipt): array
    {
        $b = $receipt->body();
        return [
            'receipt_id' => bin2hex($receipt->getId()),
            'request_id' => bin2hex($b->getRequestId()),
            'generation' => $receipt->generation,
            'provider_id' => bin2hex($b->getProviderId()),
            'execution_id' => bin2hex($b->getExecutionId()),
            'status' => match ($b->getStatus()) {
                ComputeStatus::COMPLETED => 'COMPLETED',
                ComputeStatus::FAILED => 'FAILED',
                ComputeStatus::TIMED_OUT => 'TIMED_OUT',
                ComputeStatus::TERMINATED => 'TERMINATED',
                default => 'RUNTIME_ERROR',
            },
            'exit_code' => $b->getExitCode(),
            'fuel_used' => $b->getFuelUsed() === null ? null : self::u64($b->getFuelUsed()),
            'cpu_time_ms' => self::u64($b->getCpuTimeMs()),
            'memory_peak_bytes' => self::u64($b->getMemoryPeakBytes()),
            'duration_ms' => self::u64($b->getRuntimeDurationMs()),
            'output_digest' => bin2hex($b->getOutputDigest()),
            'output_bytes_observed' => self::u64($b->getOutputBytesObserved()),
        ];
    }

    private function trustClasses(string $providerId): array
    {
        $eligibility = $this->chain->state->getProviderEligibility();
        $height = max(1, $this->chain->tip->getHeight());
        $classes = ['OPEN'];
        if ($eligibility->isEligible($providerId, TrustClass::TRUSTED, $height)) {
            $classes[] = 'TRUSTED';
        }
        if ($eligibility->isEligible($providerId, TrustClass::SOVEREIGN, $height)) {
            $classes[] = 'SOVEREIGN';
        }
        return $classes;
    }

    private function providerDto(string $id, ProviderCapability $cap): array
    {
        $reserved = $this->computeState()->reserved($id);
        return [
            'provider_id' => bin2hex($id),
            'status' => match ($cap->status) {
                ProviderCapability::STATUS_ACTIVE => 'ACTIVE',
                ProviderCapability::STATUS_DRAINING => 'DRAINING',
                default => 'SUSPENDED',
            },
            'trust_classes' => $this->trustClasses($id),
            'capability_nonce' => $cap->nonce,
            'architectures' => $cap->architectures,
            'cpu_capacity_millis' => $cap->cpuCapacityMillis,
            'memory_capacity_bytes' => self::u64($cap->memoryCapacityBytes),
            'pid_capacity' => $cap->pidCapacity,
            'max_concurrent_jobs' => $cap->maxConcurrentJobs,
            'reserved' => [
                'cpu_millis' => $reserved['cpu'],
                'memory_bytes' => self::u64($reserved['memory']),
                'pids' => $reserved['pids'],
                'jobs' => $reserved['jobs'],
            ],
            'available' => [
                'cpu_millis' => $cap->cpuCapacityMillis - $reserved['cpu'],
                'memory_bytes' => self::u64($cap->memoryCapacityBytes - $reserved['memory']),
                'pids' => $cap->pidCapacity - $reserved['pids'],
                'jobs' => $cap->maxConcurrentJobs - $reserved['jobs'],
            ],
        ];
    }

    private function providers(array $params): array
    {
        $capabilities = $this->computeState()->capabilities();
        ksort($capabilities, SORT_STRING);
        $cursor = isset($params['cursor']) ? hex2bin($params['cursor']) : null;
        $limit = isset($params['limit']) ? (int) $params['limit'] : self::DEFAULT_LIMIT;
        $items = [];
        foreach ($capabilities as $id => $cap) {
            if ($cursor !== null && strcmp($id, $cursor) <= 0) {
                continue;
            }
            $items[] = $this->providerDto($id, $cap);
            if (count($items) === $limit + 1) {
                break;
            }
        }
        $more = count($items) > $limit;
        if ($more) {
            array_pop($items);
        }
        return [
            'providers' => $items,
            'next_cursor' => $more && $items !== [] ? $items[array_key_last($items)]['provider_id'] : null,
        ];
    }

    private function provider(string $hex): array
    {
        $id = self::hexId($hex);
        $cap = $this->computeState()->capabilities()[$id] ?? null;
        if ($cap === null) {
            throw ApiError::notFound('PROVIDER_NOT_FOUND', 'Provider does not exist', ['provider_id' => $hex]);
        }
        return $this->providerDto($id, $cap);
    }

    private function reservations(string $hex): array
    {
        $id = self::hexId($hex);
        $compute = $this->computeState();
        if (!isset($compute->capabilities()[$id])) {
            throw ApiError::notFound('PROVIDER_NOT_FOUND', 'Provider does not exist', ['provider_id' => $hex]);
        }
        $reserved = $compute->reserved($id);
        return [
            'provider_id' => $hex,
            'reserved' => [
                'cpu_millis' => $reserved['cpu'],
                'memory_bytes' => self::u64($reserved['memory']),
                'pids' => $reserved['pids'],
                'jobs' => $reserved['jobs'],
            ],
            'finalized_height' => $this->chain->tip->getHeight(),
        ];
    }

    // ---- health / readiness / metrics -------------------------------------------

    private function ready(): HttpResponse
    {
        $role = ($this->role)();
        $checks = [
            // Chain reconstructs and verifies finalized history at load; reaching here means it succeeded.
            'chain_state' => true,
            'transaction_index' => $this->transactionIndexReady(),
            'identity' => $role !== 'validator' || ($this->identityReady)(),
        ];
        $ready = !in_array(false, $checks, true);
        if (!$ready) {
            throw ApiError::notReady('Node is not ready for its configured role', ['checks' => $checks]);
        }
        return HttpResponse::json(['ready' => true, 'role' => $role, 'checks' => $checks, 'finalized_height' => $this->chain->tip->getHeight()]);
    }

    private function metrics(): HttpResponse
    {
        $compute = $this->computeState();
        $requests = $assignments = $receipts = $reassignments = 0;
        foreach ($compute->logicalRecords() as [$namespace, , $value]) {
            match ($namespace) {
                'compute_requests' => $requests++,
                'compute_receipts' => $receipts++,
                default => null,
            };
            if ($namespace === 'compute_assignments') {
                $assignments++;
                $r = new \Greenwood\Codec\Reader($value, 2048);
                if (ComputeAssign::decode($r->bytes(1100))->generation > 1) {
                    $reassignments++;
                }
            }
        }
        // No high-cardinality labels (no request/transaction identifiers).
        $lines = [
            'greenwood_finalized_height ' . $this->chain->tip->getHeight(),
            'greenwood_validator_count ' . count($this->chain->genesis->getValidators()),
            'greenwood_pending_transactions ' . ($this->pendingCount)(),
            'greenwood_consensus_round ' . ($this->round)(),
            'greenwood_compute_requests_total ' . $requests,
            'greenwood_compute_assignments_total ' . $assignments,
            'greenwood_compute_receipts_total ' . $receipts,
            'greenwood_compute_reassignments_total ' . $reassignments,
            'greenwood_providers_registered ' . count($compute->capabilities()),
        ];
        return HttpResponse::text(implode("\n", $lines) . "\n");
    }
}
