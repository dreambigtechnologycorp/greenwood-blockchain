<?php

declare(strict_types=1);
namespace Greenwood\DeploymentController\Registry;

use Greenwood\DeploymentController\Registry\Rpc\{RpcError, ResponseValidator};

/** Validated, endpoint-pinned Physical V1 protocol boundary. No automatic retries. */
final class GreenwoodRpc
{
    public const CONNECT_TIMEOUT = 3;
    public const READ_TIMEOUT = 10;
    public const QUERY_TIMEOUT = 10;
    private string $endpoint;
    private bool $identityVerified = false;

    /** Transport callback returns [HTTP status, body, headers]; used by deterministic tests. */
    public function __construct(string $endpoint, private readonly ?string $expectedChainId = null, private readonly ?\Closure $transport = null)
    {
        $this->endpoint = rtrim($endpoint, '/');
    }

    private function failure(string $code, string $message, string $method, string $path, string $policy, int $status = 502, array $details = [], bool $remote = false): RpcError
    {
        return new RpcError($status, $code, $message, $details, $this->endpoint, $method, $path, $policy, $remote);
    }

    private function invoke(string $operation, array $params = [], ?array $body = null, string $query = ''): array|string
    {
        $contract = ResponseValidator::contracts()[$operation];
        $method = $contract['method']; $path = $contract['path']; $policy = $contract['policy'];
        if($method!=='GET'&&$operation!=='callContract')throw new \LogicException('Read-only registry client');
        foreach ($params as $key => $value) {
            $valid = $key === 'height' ? is_int($value) && $value >= 0 && $value <= 9999999999 : is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value);
            if (!$valid) throw $this->failure('INVALID_REQUEST', "Invalid $key", $method, $path, $policy, 400);
            $path = str_replace('{' . $key . '}', (string)$value, $path);
        }
        $path .= $query;
        if ($body !== null && isset($body['calldata']) && base64_decode($body['calldata'], true) === false) {
            throw $this->failure('INVALID_REQUEST', 'calldata must be base64', $method, $path, $policy, 400);
        }
        if ($this->expectedChainId !== null && !$this->identityVerified && $operation !== 'getChainStatus') {
            $this->getChainStatus();
        }
        $timeout = $operation === 'callContract' ? self::QUERY_TIMEOUT : self::READ_TIMEOUT;
        try {
            if ($this->transport !== null) {
                [$status, $raw, $headers] = ($this->transport)($method, $path, $body, $timeout);
            } else {
                throw new \LogicException('Explicit read-only RPC transport required');
            }
        } catch (\Throwable $e) {
            throw $this->failure('TRANSPORT_UNREACHABLE', $e->getMessage(), $method, $path, $policy === 'W' ? 'W_AMBIGUOUS' : $policy, 503);
        }
        $data = json_decode($raw, true);
        if ($status >= 400) {
            $error = is_array($data) ? ($data['error'] ?? null) : null;
            if (is_array($error) && is_string($error['code'] ?? null) && is_string($error['message'] ?? null)) {
                throw $this->failure($error['code'], $error['message'], $method, $path, $policy, $status,
                    ['remote_details'=>$error['details'] ?? [], 'retry_after'=>$headers['retry-after'] ?? null], true);
            }
            throw $this->failure('INVALID_RESPONSE', 'Remote error response has no structured error', $method, $path, $policy, $status, ['remote_body'=>$raw]);
        }
        if ($status !== 200) throw $this->failure('INVALID_RESPONSE', 'Unexpected HTTP success status', $method, $path, $policy);
        if ($operation === 'getMetrics') {
            if (!preg_match('/^greenwood_finalized_height [0-9]+$/m', $raw)) throw $this->failure('INVALID_RESPONSE', 'Invalid metrics response', $method, $path, $policy);
            return $raw;
        }
        try {
            if (!is_array($data) || !str_starts_with(ltrim($raw), '{')) throw new \UnexpectedValueException('Expected a JSON object');
            $data = ResponseValidator::result($operation, $data);
        } catch (\UnexpectedValueException $e) {
            throw $this->failure('INVALID_RESPONSE', $e->getMessage(), $method, $path, $policy);
        }
        if ($operation === 'getChainStatus') {
            if ($this->expectedChainId !== null && $data['chain_id'] !== $this->expectedChainId) throw $this->failure('NETWORK_MISMATCH', 'RPC chain ID differs from selected network', $method, $path, $policy, 409, ['expected'=>$this->expectedChainId,'actual'=>$data['chain_id']]);
            $this->identityVerified = true;
        }
        return $data;
    }

    /** LEGACY escape hatch: only known public read routes can be dispatched; normal callers use named methods. */
    public function get(string $path): array
    {
        foreach (ResponseValidator::contracts() as $operation => $c) {
            if ($c['method'] !== 'GET' || $operation === 'getMetrics') continue;
            $pattern = preg_replace('/\\\{(id|hash|height)\\\}/', '(?P<$1>[^/]+)', preg_quote($c['path'], '~'));
            if (preg_match('~^' . $pattern . '$~D', $path, $m)) {
                $params=[]; foreach (['id','hash','height'] as $key) if (isset($m[$key])) $params[$key] = $key === 'height' && ctype_digit($m[$key]) ? (int)$m[$key] : $m[$key];
                return $this->invoke($operation, $params);
            }
        }
        throw $this->failure('INVALID_REQUEST', 'Unsupported legacy RPC path', 'GET', $path, 'LEGACY', 400);
    }

    public function getHealth(): array
    { return $this->invoke('getHealth'); }

    public function getReady(): array
    { return $this->invoke('getReady'); }

    public function getMetrics(): string
    { return $this->invoke('getMetrics'); }

    public function getChainStatus(): array
    { return $this->invoke('getChainStatus'); }

    public function getFinality(): array
    { return $this->invoke('getFinality'); }

    public function getValidators(): array
    { return $this->invoke('getValidators'); }

    public function getStateRoot(): array
    { return $this->invoke('getStateRoot'); }

    public function getBlock(int $height): array
    { return $this->invoke('getBlock', ['height'=>$height]); }

    public function getBlockByHash(string $hash): array
    { return $this->invoke('getBlockByHash', ['hash'=>$hash]); }

    public function getAccount(string $id): array
    { return $this->invoke('getAccount', ['id'=>$id]); }

    public function getBalance(string $id): array
    { return $this->invoke('getBalance', ['id'=>$id]); }

    public function getNonce(string $id): array
    { return $this->invoke('getNonce', ['id'=>$id]); }

    public function getContract(string $id): array
    { return $this->invoke('getContract', ['id'=>$id]); }

    public function getTransaction(string $hash): array
    { return $this->invoke('getTransaction', ['hash'=>$hash]); }

    public function getTransactionStatus(string $hash): array
    { return $this->invoke('getTransactionStatus', ['hash'=>$hash]); }

    public function getTransactionReceipt(string $hash): array
    { return $this->invoke('getTransactionReceipt', ['hash'=>$hash]); }

    public function getComputeProvider(string $id): array
    { return $this->invoke('getComputeProvider', ['id'=>$id]); }

    public function getComputeProviderReservations(string $id): array
    { return $this->invoke('getComputeProviderReservations', ['id'=>$id]); }

    public function getComputeRequest(string $id): array
    { return $this->invoke('getComputeRequest', ['id'=>$id]); }

    public function getComputeRequestHistory(string $id): array
    { return $this->invoke('getComputeRequestHistory', ['id'=>$id]); }

    public function getComputeRequestReceipt(string $id): array
    { return $this->invoke('getComputeRequestReceipt', ['id'=>$id]); }

    public function getComputeReceipt(string $id): array
    { return $this->invoke('getComputeReceipt', ['id'=>$id]); }

    public function callContract(string $contractId, string $method, string $calldata = ''): array
    { return $this->invoke('callContract', ['id'=>$contractId], ['method'=>$method,'calldata'=>$calldata]); }

    public function getComputeProviders(int $limit = 50, ?string $cursor = null): array
    {
        if ($limit < 1 || $limit > 200 || ($cursor !== null && !preg_match('/^[0-9a-f]{64}$/D',$cursor))) {
            throw $this->failure('INVALID_REQUEST','Invalid pagination','GET','/v1/compute/providers','R2',400);
        }
        return $this->invoke('getComputeProviders', [], null, '?' . http_build_query(array_filter(['limit'=>$limit,'cursor'=>$cursor], fn($v)=>$v!==null)));
    }

    /** Legacy observation helper. Timeout is not execution failure; B4 owns waiting/reconciliation. */
    public function waitForFinality(string $hash, int $timeout = 60): array
    {
        $start = time();
        while (time()-$start < $timeout) {
            $status=$this->getTransactionStatus($hash);
            if ($status['status']==='FINALIZED') return $status;
            sleep(2);
        }
        throw $this->failure('OBSERVATION_TIMEOUT','Finality not observed within local deadline','GET','/v1/transactions/'.$hash.'/status','R3/R2',504);
    }
}
