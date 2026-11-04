<?php

declare(strict_types=1);

namespace Greenwood\Cli;

/**
 * bin/greenwood — pure RPC client CLI. Never reads state.sqlite, provider.sqlite
 * or block files. Signing stays local and explicit (see greenwood-cli); private
 * keys are never sent to any RPC endpoint.
 *
 * Exit codes (frozen):
 *   0 success · 1 other failure · 2 invalid local input
 *   3 authentication failure · 4 remote not found · 5 conflict / not ready
 */
final class GreenwoodCli
{
    public const EXIT_OK = 0;
    public const EXIT_FAILURE = 1;
    public const EXIT_USAGE = 2;
    public const EXIT_AUTH = 3;
    public const EXIT_NOT_FOUND = 4;
    public const EXIT_CONFLICT = 5;

    /**
     * @param callable(string):object $clientFactory endpoint -> RpcClient-like object with json()
     * @param callable(string):void   $out stdout writer
     * @param callable(string):void   $err stderr writer
     */
    public static function run(array $argv, callable $clientFactory, callable $out, callable $err): int
    {
        try {
            return self::dispatch($argv, $clientFactory, $out, $err);
        } catch (\InvalidArgumentException $e) {
            $err('error: ' . $e->getMessage() . "\n");
            return self::EXIT_USAGE;
        } catch (\Throwable $e) {
            $err('error: ' . $e->getMessage() . "\n");
            return self::EXIT_FAILURE;
        }
    }

    private static function dispatch(array $argv, callable $clientFactory, callable $out, callable $err): int
    {
        $flags = ['json' => false, 'rpc' => null, 'operator' => null, 'token-file' => null, 'config' => null];
        $positionals = [];
        $extra = [];
        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if ($arg === '--json') {
                $flags['json'] = true;
            } elseif (preg_match('/^--(rpc|operator|token-file|config)=(.+)$/', $arg, $m)) {
                $flags[$m[1]] = $m[2];
            } elseif (preg_match('/^--(status|arch|cpu|memory|pids|jobs)=(.*)$/', $arg, $m)) {
                $extra[$m[1]][] = $m[2];
            } elseif (str_starts_with($arg, '--')) {
                throw new \InvalidArgumentException('Unknown option: ' . $arg);
            } else {
                $positionals[] = $arg;
            }
        }

        $config = [];
        if ($flags['config'] !== null) {
            $raw = @file_get_contents($flags['config'], false, null, 0, 8193);
            if ($raw === false || strlen($raw) > 8192) {
                throw new \InvalidArgumentException('Cannot read CLI config file');
            }
            $config = json_decode($raw, true, 8);
            if (!is_array($config) || array_is_list($config)) {
                throw new \InvalidArgumentException('CLI config must be a JSON object');
            }
            foreach ($config as $k => $v) {
                if (!in_array($k, ['rpc_endpoint', 'operator_endpoint', 'operator_token_file', 'output'], true) || !is_string($v)) {
                    throw new \InvalidArgumentException('Invalid CLI config field: ' . $k);
                }
            }
        }
        $rpcEndpoint = $flags['rpc'] ?? $config['rpc_endpoint'] ?? 'http://127.0.0.1:9201';
        $operatorEndpoint = $flags['operator'] ?? $config['operator_endpoint'] ?? 'unix:///run/greenwood/operator.sock';
        $tokenFile = $flags['token-file'] ?? $config['operator_token_file'] ?? null;
        $json = $flags['json'] || (($config['output'] ?? null) === 'json');

        $group = $positionals[0] ?? 'help';
        $verb = $positionals[1] ?? null;
        $args = array_slice($positionals, 2);

        if ($group === 'help' || $verb === null) {
            $out(self::usage());
            return $group === 'help' ? self::EXIT_OK : self::EXIT_USAGE;
        }

        $client = $clientFactory($rpcEndpoint);
        [$method, $path, $body] = match ($group) {
            'chain' => self::chainCommand($verb, $args),
            'block' => self::blockCommand($verb, $args),
            'account' => self::accountCommand($verb, $args),
            'tx' => self::txCommand($verb, $args),
            'compute' => self::computeCommand($verb, $args),
            'provider' => self::providerCommand($verb, $args, $extra),
            default => throw new \InvalidArgumentException('Unknown command group: ' . $group),
        };
        // Provider commands switch the client to the operator endpoint with a bearer token.
        if ($group === 'provider') {
            $client = $clientFactory($operatorEndpoint);
            $token = self::loadToken($tokenFile);
            $r = $client->json($method, $path, $body, $token);
        } else {
            $r = $client->json($method, $path, $body);
        }
        return self::respond($r['status'], $r['data'], $json, $out, $err);
    }

    private static function id(string $value, string $what = 'identifier'): string
    {
        if (!preg_match('/^[0-9a-f]{64}$/D', $value)) {
            throw new \InvalidArgumentException('Invalid ' . $what . ': expected 64 lowercase hex characters');
        }
        return $value;
    }

    private static function need(array $args, int $count): void
    {
        if (count($args) !== $count) {
            throw new \InvalidArgumentException('Wrong number of arguments');
        }
    }

    private static function chainCommand(string $verb, array $args): array
    {
        self::need($args, 0);
        return match ($verb) {
            'status' => ['GET', '/v1/chain/status', null],
            'finality' => ['GET', '/v1/chain/finality', null],
            'validators' => ['GET', '/v1/chain/validators', null],
            default => throw new \InvalidArgumentException('Unknown chain command: ' . $verb),
        };
    }

    private static function blockCommand(string $verb, array $args): array
    {
        if ($verb !== 'get') {
            throw new \InvalidArgumentException('Unknown block command: ' . $verb);
        }
        self::need($args, 1);
        if (preg_match('/^\d{1,10}$/D', $args[0])) {
            return ['GET', '/v1/blocks/' . $args[0], null];
        }
        return ['GET', '/v1/blocks/hash/' . self::id($args[0], 'block hash'), null];
    }

    private static function accountCommand(string $verb, array $args): array
    {
        self::need($args, 1);
        $id = self::id($args[0], 'account id');
        return match ($verb) {
            'get' => ['GET', '/v1/accounts/' . $id, null],
            'balance' => ['GET', '/v1/accounts/' . $id . '/balance', null],
            'nonce' => ['GET', '/v1/accounts/' . $id . '/nonce', null],
            default => throw new \InvalidArgumentException('Unknown account command: ' . $verb),
        };
    }

    private static function txCommand(string $verb, array $args): array
    {
        self::need($args, 1);
        if ($verb === 'submit') {
            $raw = @file_get_contents($args[0], false, null, 0, 1500001);
            if ($raw === false || strlen($raw) > 1500000) {
                throw new \InvalidArgumentException('Cannot read transaction file');
            }
            $data = json_decode($raw, true, 8);
            // Pre-signed envelope exactly as produced by local signing (greenwood-cli transfer-sign).
            if (!is_array($data) || ($data['type'] ?? null) !== 'transaction' || !is_string($data['payload'] ?? null)
                || base64_decode($data['payload'], true) === false) {
                throw new \InvalidArgumentException('Transaction file must be a signed envelope {type: "transaction", payload: base64}');
            }
            return ['POST', '/v1/transactions', json_encode(['type' => 'transaction', 'payload' => $data['payload']], JSON_THROW_ON_ERROR)];
        }
        $hash = self::id($args[0], 'transaction hash');
        return match ($verb) {
            'get' => ['GET', '/v1/transactions/' . $hash, null],
            'status' => ['GET', '/v1/transactions/' . $hash . '/status', null],
            default => throw new \InvalidArgumentException('Unknown tx command: ' . $verb),
        };
    }

    private static function computeCommand(string $verb, array $args): array
    {
        if ($verb === 'providers') {
            self::need($args, 0);
            return ['GET', '/v1/compute/providers', null];
        }
        if ($verb === 'provider') {
            self::need($args, 1);
            return ['GET', '/v1/compute/providers/' . self::id($args[0], 'provider id'), null];
        }
        self::need($args, 1);
        $id = self::id($args[0], 'request id');
        return match ($verb) {
            'request' => ['GET', '/v1/compute/requests/' . $id, null],
            'history' => ['GET', '/v1/compute/requests/' . $id . '/history', null],
            'receipt' => ['GET', '/v1/compute/requests/' . $id . '/receipt', null],
            default => throw new \InvalidArgumentException('Unknown compute command: ' . $verb),
        };
    }


    private static function providerCommand(string $verb, array $args, array $extra): array
    {
        self::need($args, 0);
        switch ($verb) {
            case 'status': return ['GET', '/v1/operator/provider/status', null];
            case 'jobs': return ['GET', '/v1/operator/provider/jobs', null];
            case 'runtime': return ['GET', '/v1/operator/provider/runtime', null];
            case 'drain': return ['POST', '/v1/operator/provider/drain', '{}'];
            case 'resume': return ['POST', '/v1/operator/provider/resume', '{}'];
            case 'capability':
                $body = [];
                if (isset($extra['status'])) {
                    $body['status'] = strtoupper($extra['status'][0]);
                    if (!in_array($body['status'], ['ACTIVE', 'DRAINING', 'SUSPENDED'], true)) {
                        throw new \InvalidArgumentException('--status must be ACTIVE, DRAINING or SUSPENDED');
                    }
                }
                if (isset($extra['arch'])) {
                    $archs = [];
                    foreach ($extra['arch'] as $list) {
                        foreach (explode(',', $list) as $a) {
                            $archs[] = trim($a);
                        }
                    }
                    $body['architectures'] = array_values(array_unique($archs));
                }
                foreach (['cpu' => 'cpu_capacity_millis', 'memory' => 'memory_capacity_bytes', 'pids' => 'pid_capacity', 'jobs' => 'max_concurrent_jobs'] as $flag => $field) {
                    if (isset($extra[$flag])) {
                        if (!preg_match('/^\d+$/D', $extra[$flag][0]) || (int) $extra[$flag][0] < 1) {
                            throw new \InvalidArgumentException('--' . $flag . ' must be a positive integer');
                        }
                        $body[$field] = (int) $extra[$flag][0];
                    }
                }
                if ($body === []) {
                    throw new \InvalidArgumentException('provider capability requires at least one field flag');
                }
                return ['POST', '/v1/operator/provider/capability', json_encode($body, JSON_THROW_ON_ERROR)];
        }
        throw new \InvalidArgumentException('Unknown provider command: ' . $verb);
    }

    private static function loadToken(?string $tokenFile): string
    {
        if ($tokenFile === null) {
            throw new \InvalidArgumentException('Operator commands require --token-file or operator_token_file config');
        }
        $raw = @file_get_contents($tokenFile, false, null, 0, 4097);
        if ($raw === false || strlen($raw) > 4096) {
            throw new \InvalidArgumentException('Cannot read operator token file');
        }
        $token = rtrim($raw, "\r\n");
        if (strlen($token) < 16) {
            throw new \InvalidArgumentException('Operator token file is invalid');
        }
        return $token; // Never printed or logged.
    }

    private static function respond(int $status, array $data, bool $json, callable $out, callable $err): int
    {
        if ($status >= 200 && $status < 300) {
            $out(($json ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : self::human($data)) . "\n");
            return self::EXIT_OK;
        }
        $error = $data['error'] ?? null;
        $code = is_array($error) ? ($error['code'] ?? 'INTERNAL_ERROR') : 'INTERNAL_ERROR';
        $message = is_array($error) ? ($error['message'] ?? 'request failed') : 'request failed';
        if ($json) {
            $out(json_encode(['error' => ['code' => $code, 'message' => $message, 'details' => $error['details'] ?? new \stdClass()]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        } else {
            $err('error: ' . $code . ': ' . $message . "\n");
        }
        return match ($status) {
            400 => self::EXIT_USAGE,
            401, 403 => self::EXIT_AUTH,
            404 => self::EXIT_NOT_FOUND,
            default => self::EXIT_CONFLICT, // 409, 422, 429, 503
        };
    }

    private static function human(array $data, string $prefix = ''): string
    {
        $lines = [];
        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $lines[] = self::human($value, $name);
            } else {
                $lines[] = $name . ': ' . match (true) {
                    $value === null => 'null',
                    $value === true => 'true',
                    $value === false => 'false',
                    default => self::safe((string) $value),
                };
            }
        }
        return implode("\n", $lines);
    }

    /** Human output never passes terminal control sequences from remote data. */
    private static function safe(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '?', $value);
    }

    private static function usage(): string
    {
        return <<<TXT
        greenwood — Greenwood RPC client (no direct database access)

        greenwood [--json] [--rpc=URL] [--operator=URL] [--token-file=PATH] [--config=PATH] <command>

          chain status|finality|validators
          block get <height|hash>
          account get|balance|nonce <account>
          tx submit <envelope.json> | tx get|status <hash>
          compute request|history|receipt <id> | compute providers | compute provider <id>
          provider status|jobs|runtime|drain|resume
          provider capability [--status=ACTIVE|DRAINING|SUSPENDED] [--arch=linux/arm64/v8,...]
                              [--cpu=N] [--memory=N] [--pids=N] [--jobs=N]

        Exit codes: 0 ok · 1 failure · 2 invalid input · 3 auth · 4 not found · 5 conflict/not ready
        TXT;
    }
}
