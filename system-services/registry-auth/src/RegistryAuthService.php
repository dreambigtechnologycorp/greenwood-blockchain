<?php

declare(strict_types=1);

namespace Greenwood\SystemServices\RegistryAuth;

use Greenwood\Crypto\ProtocolHash;
use Greenwood\DeploymentController\Auth\DeploymentIdentity;
use Greenwood\DeploymentController\Registry\{ApplicationRegistryV1Client,GreenwoodRpc,ReadOnlyRpc};
use PDO;

final class RegistryAuthService
{
    private const CHALLENGE_PREFIX =
        'GREENWOOD-REGISTRY-AUTH-V2';

    private const REGISTRY_SERVICE =
        'greenwood-registry';

    private const ISSUER =
        'greenwood-registry-auth';

    private const CAP_PUBLISH = 1;

    private const CHALLENGE_TTL = 120;

    // A bounded push session can renew short-lived bearer tokens during uploads.
    private const SESSION_TTL = 1800;

    private const TOKEN_TTL = 120;

    public function __construct(
        private readonly PDO $db,
        private readonly string $chainId,
        private readonly string $rpcEndpoint,
        private readonly string $applicationRegistryContract,
        private readonly string $issuerKeypair,
        private readonly ?GreenwoodRpc $authorityRpc = null,
    ) {
        if (
            !preg_match(
                '/^[0-9a-f]{64}$/D',
                $applicationRegistryContract
            )
        ) {
            throw new \InvalidArgumentException(
                'Invalid ApplicationRegistryV1 contract ID'
            );
        }

        if ($chainId !== DeploymentIdentity::CHAIN || $applicationRegistryContract !== DeploymentIdentity::REGISTRY) {
            throw new \InvalidArgumentException('Unapproved registry authority');
        }

        if (
            strlen($issuerKeypair)
            !== SODIUM_CRYPTO_SIGN_KEYPAIRBYTES
        ) {
            throw new \InvalidArgumentException(
                'Registry issuer keypair has invalid length'
            );
        }
    }

    public function challenge(
        int $applicationId,
        string $service,
        array $actions
    ): array {
        if ($applicationId < 1 || $applicationId > 0xffffffff) {
            throw new \InvalidArgumentException(
                'application_id must be positive'
            );
        }

        self::validateService($service);
        $actions = self::normalizeActions($actions);

        $id = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(32));
        $repository =
            self::repository($applicationId, $service);

        $issuedAt = time();
        $expiresAt =
            $issuedAt + self::CHALLENGE_TTL;

        $stmt = $this->db->prepare(
            'INSERT INTO registry_challenges (
                id,
                chain_id,
                nonce,
                application_id,
                service,
                repository,
                actions_json,
                issued_at,
                expires_at,
                consumed_at
             ) VALUES (
                :id,
                :chain_id,
                :nonce,
                :application_id,
                :service,
                :repository,
                :actions_json,
                :issued_at,
                :expires_at,
                NULL
             )'
        );

        $stmt->execute([
            ':id' => $id,
            ':chain_id' => $this->chainId,
            ':nonce' => $nonce,
            ':application_id' => $applicationId,
            ':service' => $service,
            ':repository' => $repository,
            ':actions_json' =>
                json_encode(
                    $actions,
                    JSON_THROW_ON_ERROR
                ),
            ':issued_at' => $issuedAt,
            ':expires_at' => $expiresAt,
        ]);

        return [
            'id' => $id,
            'nonce' => $nonce,
            'chain_id' => $this->chainId,
            'registry_contract_id' => $this->applicationRegistryContract,
            'application_id' => $applicationId,
            'service' => $service,
            'repository' => $repository,
            'actions' => $actions,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'canonical_bytes' =>
                self::canonicalChallenge(
                    $this->chainId,
                    $applicationId,
                    $service,
                    $repository,
                    $actions,
                    $nonce,
                    $issuedAt,
                    $expiresAt
                ),
        ];
    }

    public function authorize(
        string $challengeId,
        string $publicKey,
        string $signature
    ): array {
        if (
            strlen($publicKey) !== 32
            || strlen($signature) !== 64
        ) {
            return [
                'success' => false,
                'error' => 'Invalid signature material',
            ];
        }

        $stmt = $this->db->prepare(
            'SELECT *
             FROM registry_challenges
             WHERE id = :id'
        );

        $stmt->execute([
            ':id' => $challengeId,
        ]);

        $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($challenge === false) {
            return [
                'success' => false,
                'error' => 'Challenge not found',
            ];
        }

        if ($challenge['chain_id'] !== $this->chainId
            || $challenge['repository'] !== self::repository((int)$challenge['application_id'], (string)$challenge['service'])) {
            return ['success' => false, 'error' => 'Challenge registry scope mismatch'];
        }

        if (
            $challenge['consumed_at'] !== null
        ) {
            return [
                'success' => false,
                'error' => 'Challenge already consumed',
            ];
        }

        if (
            (int) $challenge['expires_at']
            <= time()
        ) {
            return [
                'success' => false,
                'error' => 'Challenge expired',
            ];
        }

        $actions = json_decode(
            (string) $challenge['actions_json'],
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $canonical =
            self::canonicalChallenge(
                (string) $challenge['chain_id'],
                (int) $challenge['application_id'],
                (string) $challenge['service'],
                (string) $challenge['repository'],
                $actions,
                (string) $challenge['nonce'],
                (int) $challenge['issued_at'],
                (int) $challenge['expires_at']
            );

        if (
            !sodium_crypto_sign_verify_detached(
                $signature,
                $canonical,
                $publicKey
            )
        ) {
            return [
                'success' => false,
                'error' => 'Invalid signature',
            ];
        }

        $accountId = ProtocolHash::hash(
            'GREENWOOD_ACCOUNT_V1',
            $publicKey
        );

        $capabilities =
            $this->applicationCapabilities(
                (int) $challenge['application_id'],
                $accountId
            );

        if ($capabilities === 0) {
            return ['success' => false, 'error' => 'Application authority required'];
        }

        if (
            in_array(
                'push',
                $actions,
                true
            )
            && (
                $capabilities
                & self::CAP_PUBLISH
            ) === 0
        ) {
            return [
                'success' => false,
                'error' =>
                    'Insufficient authority: PUBLISH required',
            ];
        }

        $this->db->beginTransaction();

        try {
            $consume = $this->db->prepare(
                'UPDATE registry_challenges
                 SET consumed_at = :consumed_at
                 WHERE id = :id
                   AND consumed_at IS NULL
                   AND expires_at > :now'
            );

            $now = time();

            $consume->execute([
                ':consumed_at' => $now,
                ':id' => $challengeId,
                ':now' => $now,
            ]);

            if ($consume->rowCount() !== 1) {
                $this->db->rollBack();

                return [
                    'success' => false,
                    'error' =>
                        'Challenge already consumed',
                ];
            }

            $sessionId =
                bin2hex(random_bytes(16));

            $credential =
                bin2hex(random_bytes(32));

            $expiresAt =
                $now + self::SESSION_TTL;

            $session = $this->db->prepare(
                'INSERT INTO registry_sessions (
                    session_id,
                    credential_hash,
                    account_id,
                    repository,
                    actions_json,
                    issued_at,
                    expires_at
                 ) VALUES (
                    :session_id,
                    :credential_hash,
                    :account_id,
                    :repository,
                    :actions_json,
                    :issued_at,
                    :expires_at
                 )'
            );

            $session->execute([
                ':session_id' =>
                    $sessionId,
                ':credential_hash' =>
                    hash(
                        'sha256',
                        $credential
                    ),
                ':account_id' =>
                    bin2hex($accountId),
                ':repository' =>
                    $challenge['repository'],
                ':actions_json' =>
                    json_encode(
                        $actions,
                        JSON_THROW_ON_ERROR
                    ),
                ':issued_at' => $now,
                ':expires_at' => $expiresAt,
            ]);

            $this->db->commit();

            return [
                'success' => true,
                'session_id' => $sessionId,
                'credential' => $credential,
                'expires_at' => $expiresAt,
            ];
        } catch (\Throwable $e) {
            if (
                $this->db->inTransaction()
            ) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    public function token(
        string $credential,
        string $service,
        string $scope
    ): array {
        if (
            $credential === ''
        ) {
            return [
                'error' =>
                    'credential is required',
            ];
        }

        if (
            $service
            !== self::REGISTRY_SERVICE
        ) {
            return [
                'error' =>
                    'Invalid registry service',
            ];
        }

        $stmt = $this->db->prepare(
            'SELECT *
             FROM registry_sessions
             WHERE credential_hash = :hash'
        );

        $stmt->execute([
            ':hash' =>
                hash(
                    'sha256',
                    $credential
                ),
        ]);

        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($session === false) {
            return [
                'error' =>
                    'Invalid credential',
            ];
        }

        if (
            (int) $session['expires_at']
            <= time()
        ) {
            return [
                'error' =>
                    'Session expired',
            ];
        }

        // Docker's initial /v2/ exchange can request no repository permissions.
        // Validate the session's scope even then; never inherit legacy sessions.
        $sessionScope = self::parseDistributionScope('repository:' . $session['repository'] . ':pull');
        $parsed = $scope === '' ? ['repository' => $session['repository'], 'actions' => [],
            'application_id' => $sessionScope['application_id']] : self::parseDistributionScope($scope);

        if (
            $parsed['repository']
            !== $session['repository']
        ) {
            return [
                'error' =>
                    'Repository mismatch',
            ];
        }

        $allowed = json_decode(
            (string) $session['actions_json'],
            true,
            flags: JSON_THROW_ON_ERROR
        );

        foreach (
            $parsed['actions']
            as $action
        ) {
            if (
                !in_array(
                    $action,
                    $allowed,
                    true
                )
            ) {
                return [
                    'error' =>
                        'Requested actions exceed session scope',
                ];
            }
        }

        $applicationId = $parsed['application_id'];
        $account = hex2bin((string) $session['account_id']);
        $capabilities = $account === false ? 0 : $this->applicationCapabilities($applicationId, $account);
        if ($capabilities === 0 || (in_array('push', $parsed['actions'], true) && ($capabilities & self::CAP_PUBLISH) === 0)) {
            return ['error' => 'Application authority revoked or unavailable'];
        }
        $now = time();
        $remaining = (int)$session['expires_at'] - $now;
        // Docker clients assume at least 60 seconds; never advertise beyond expiry.
        if ($remaining < 60) return ['error' => 'Session renewal required'];

        return $this->issueToken(
            (string) $session['account_id'],
            (string) $session['repository'],
            $parsed['actions'],
            min(self::TOKEN_TTL, $remaining),
            $now
        );
    }

    public function issuerPublicKey(): string
    {
        return
            sodium_crypto_sign_publickey(
                $this->issuerKeypair
            );
    }

    private function applicationCapabilities(int $applicationId, string $accountId): int
    {
        if (strlen($accountId) !== 32 || $applicationId < 1 || $applicationId > 0xffffffff) return 0;
        try {
            $started = time();
            $rpc = $this->authorityRpc ?? ReadOnlyRpc::connect($this->rpcEndpoint, $this->chainId);
            $before = $rpc->getChainStatus();
            if ($before['chain_id'] !== $this->chainId || $before['syncing'] !== false
                || $before['height'] !== $before['finalized_height']) return 0;
            $meta = $rpc->getContract($this->applicationRegistryContract);
            if (($meta['contract_id'] ?? null) !== $this->applicationRegistryContract
                || ($meta['wasm_hash'] ?? null) !== $this->applicationRegistryContract
                || ($meta['abi_version'] ?? null) !== 1
                || ($meta['finalized_height'] ?? null) !== $before['finalized_height']) return 0;
            $registry = new ApplicationRegistryV1Client($rpc, $this->applicationRegistryContract);
            $registry->atFinalizedHeight($before['finalized_height']);
            $registry->application($applicationId);
            $capabilities = $registry->authority($applicationId, $accountId);
            $after = $rpc->getChainStatus();
            foreach (['chain_id', 'height', 'finalized_height', 'block_hash', 'state_root', 'syncing'] as $field) {
                if (!array_key_exists($field, $before) || !array_key_exists($field, $after)
                    || $before[$field] !== $after[$field]) return 0;
            }
            return time() - $started <= 15 && ($capabilities & ~ApplicationRegistryV1Client::CAP_ALL) === 0
                ? $capabilities : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function issueToken(
        string $accountIdHex,
        string $repository,
        array $actions,
        int $ttl,
        int $now
    ): array {

        $header =
            self::base64url(
                json_encode(
                    [
                        'alg' => 'EdDSA',
                        'typ' => 'JWT',
                    ],
                    JSON_THROW_ON_ERROR
                )
            );

        $payload =
            self::base64url(
                json_encode(
                    [
                        'iss' => self::ISSUER,
                        'sub' => $accountIdHex,
                        'aud' => [
                            self::REGISTRY_SERVICE,
                        ],
                        'iat' => $now,
                        'nbf' => $now,
                        'exp' => $now + $ttl,
                        'jti' =>
                            bin2hex(
                                random_bytes(8)
                            ),
                        'access' => [
                            [
                                'type' =>
                                    'repository',
                                'name' =>
                                    $repository,
                                'actions' =>
                                    $actions,
                            ],
                        ],
                    ],
                    JSON_THROW_ON_ERROR
                )
            );

        $signingInput =
            $header . '.' . $payload;

        $signature =
            sodium_crypto_sign_detached(
                $signingInput,
                sodium_crypto_sign_secretkey(
                    $this->issuerKeypair
                )
            );

        return [
            'token' => $signingInput
            . '.'
            . self::base64url(
                $signature
            ),
            'expires_in' => $ttl,
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
        ];
    }

    private static function canonicalChallenge(
        string $chainId,
        int $applicationId,
        string $service,
        string $repository,
        array $actions,
        string $nonce,
        int $issuedAt,
        int $expiresAt
    ): string {
        return implode(
            "\n",
            [
                self::CHALLENGE_PREFIX,
                'chain_id=' . $chainId,
                'registry_contract_id=' . DeploymentIdentity::REGISTRY,
                'registry_service='
                    . self::REGISTRY_SERVICE,
                'application_id='
                    . $applicationId,
                'service=' . $service,
                'repository='
                    . $repository,
                'actions='
                    . implode(',', $actions),
                'nonce=' . $nonce,
                'issued_at=' . $issuedAt,
                'expires_at=' . $expiresAt,
            ]
        );
    }

    private static function repository(
        int $applicationId,
        string $service
    ): string {
        return
            'chains/' . DeploymentIdentity::CHAIN . '/registries/' . DeploymentIdentity::REGISTRY . '/apps/'
            . $applicationId
            . '/'
            . $service;
    }

    private static function validateService(
        string $service
    ): void {
        if (
            preg_match(
                '/^[a-z0-9][a-z0-9._-]{0,62}$/D',
                $service
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Invalid registry service name'
            );
        }
    }

    private static function normalizeActions(
        array $actions
    ): array {
        $out = [];

        foreach ($actions as $action) {
            if (
                !is_string($action)
                || !in_array(
                    $action,
                    ['pull', 'push'],
                    true
                )
            ) {
                throw new \InvalidArgumentException(
                    'Invalid registry action'
                );
            }

            if (
                !in_array(
                    $action,
                    $out,
                    true
                )
            ) {
                $out[] = $action;
            }
        }

        if ($out === []) {
            throw new \InvalidArgumentException(
                'At least one registry action is required'
            );
        }

        return $out;
    }

    private static function parseDistributionScope(
        string $scope
    ): array {
        if (
            !preg_match(
                '~^repository:(chains/' . preg_quote(DeploymentIdentity::CHAIN, '~') . '/registries/'
                . DeploymentIdentity::REGISTRY . '/apps/([1-9][0-9]{0,9})/[a-z0-9][a-z0-9._-]{0,62}):(pull|push)(,(pull|push))*$~D',
                $scope, $matches
            )
            || (int)$matches[2] > 0xffffffff
        ) {
            throw new \InvalidArgumentException(
                'Invalid scope format'
            );
        }

        $parts =
            explode(':', $scope, 3);

        $actions =
            array_values(
                array_unique(
                    explode(',', $parts[2])
                )
            );

        return [
            'repository' => $parts[1],
            'actions' => $actions,
            'application_id' => (int)$matches[2],
        ];
    }

    private static function base64url(
        string $bytes
    ): string {
        return rtrim(
            strtr(
                base64_encode($bytes),
                '+/',
                '-_'
            ),
            '='
        );
    }
}
