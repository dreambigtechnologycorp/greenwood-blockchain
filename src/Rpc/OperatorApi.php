<?php

declare(strict_types=1);

namespace Greenwood\Rpc;

use Greenwood\Compute\Containerd\RegistryTransferAuth;

/**
 * Pass 5 operator control plane (/v1/operator/*). Served ONLY on a dedicated
 * Unix-socket or loopback listener, never on the public node listener. All
 * routes are OPERATOR_ONLY and pass through the single OperatorAuth policy.
 * Mutations delegate to the provider operator adapter, which signs and submits
 * canonical TYPE_PROVIDER_CAPABILITY transactions — no direct state mutation.
 */
final class OperatorApi
{
    public const ROUTES = [
        'GET /v1/operator/provider/status' => 'OPERATOR_ONLY',
        'GET /v1/operator/provider/jobs' => 'OPERATOR_ONLY',
        'GET /v1/operator/provider/runtime' => 'OPERATOR_ONLY',
        'POST /v1/operator/provider/drain' => 'OPERATOR_ONLY',
        'POST /v1/operator/provider/resume' => 'OPERATOR_ONLY',
        'POST /v1/operator/provider/capability' => 'OPERATOR_ONLY',
        'POST /v1/operator/provider/applications/start' => 'OPERATOR_ONLY',
        'POST /v1/operator/provider/applications/reserve' => 'OPERATOR_ONLY',
        'POST /v1/operator/provider/applications/inspect' => 'OPERATOR_ONLY',
        'POST /v1/operator/provider/applications/stop' => 'OPERATOR_ONLY',
        'GET /v1/operator/validator/status' => 'OPERATOR_ONLY',
        'GET /v1/operator/validator/consensus' => 'OPERATOR_ONLY',
    ];

    private const MAX_BODY = 65536;

    /** Null closure = route group not offered by this process. */
    public function __construct(
        private readonly OperatorAuth $auth,
        private readonly ?\Closure $providerStatus = null,
        private readonly ?\Closure $providerJobs = null,
        private readonly ?\Closure $providerRuntime = null,
        private readonly ?\Closure $providerDrain = null,
        private readonly ?\Closure $providerResume = null,
        private readonly ?\Closure $providerCapability = null, // (array $input): array
        private readonly ?\Closure $providerApplicationStart = null,
        private readonly ?\Closure $providerApplicationInspect = null,
        private readonly ?\Closure $providerApplicationStop = null,
        private readonly ?\Closure $validatorStatus = null,
        private readonly ?\Closure $validatorConsensus = null,
        private readonly ?\Closure $providerApplicationReserve = null,
    ) {
    }

    public function handle(string $method, string $path, string $body, array $headers = []): HttpResponse
    {
        try {
            return $this->route($method, $path, $body, $headers);
        } catch (ApiError $e) {
            return HttpResponse::error($e);
        } catch (\InvalidArgumentException $e) {
            return HttpResponse::error(ApiError::invalidRequest($e->getMessage()));
        } catch (\Throwable) {
            return HttpResponse::error(ApiError::internal());
        }
    }

    private function route(string $method, string $path, string $body, array $headers): HttpResponse
    {
        $route = explode('?', $path, 2)[0];
        if (!str_starts_with($route, '/v1/operator/')) {
            throw ApiError::notFound('NOT_FOUND', 'Unknown route');
        }
        $this->auth->authorize($headers);

        if ($method === 'GET') {
            $handler = match ($route) {
                '/v1/operator/provider/status' => $this->providerStatus,
                '/v1/operator/provider/jobs' => $this->providerJobs,
                '/v1/operator/provider/runtime' => $this->providerRuntime,
                '/v1/operator/validator/status' => $this->validatorStatus,
                '/v1/operator/validator/consensus' => $this->validatorConsensus,
                default => throw ApiError::notFound('NOT_FOUND', 'Unknown operator route'),
            };
            if ($handler === null) {
                throw ApiError::notFound('NOT_FOUND', 'Route not offered by this process');
            }
            return HttpResponse::json($handler());
        }
        if ($method === 'POST') {
            if ($route === '/v1/operator/provider/applications/reserve') {
                if ($this->providerApplicationReserve === null) throw ApiError::notFound('NOT_FOUND', 'Route not offered by this process');
                $data = $this->applicationObject($body);
                $keys = ['application_id', 'service', 'image_ref'];
                if (array_diff(array_keys($data), $keys) || array_diff($keys, array_keys($data))) throw ApiError::invalidRequest('Invalid reservation fields');
                [$id, $service] = $this->applicationIdentity($data);
                $image = $data['image_ref'];
                if (!is_string($image) || strlen($image)>4096 || preg_match('/[\x00-\x20\x7f]/', $image)
                    || !preg_match('/@sha256:[a-f0-9]{64}$/D', $image)) throw ApiError::invalidRequest('Immutable image required');
                return HttpResponse::json(($this->providerApplicationReserve)($id, $service, $image));
            }
            if (
                $route
                === '/v1/operator/provider/applications/start'
            ) {
                if ($this->providerApplicationStart === null) {
                    throw ApiError::notFound(
                        'NOT_FOUND',
                        'Route not offered by this process'
                    );
                }

                return HttpResponse::json(
                    ($this->providerApplicationStart)(
                        ...$this->applicationStartInput(
                            $body
                        )
                    )
                );
            }

            if (
                $route
                === '/v1/operator/provider/applications/inspect'
            ) {
                if ($this->providerApplicationInspect === null) {
                    throw ApiError::notFound(
                        'NOT_FOUND',
                        'Route not offered by this process'
                    );
                }

                return HttpResponse::json(
                    ($this->providerApplicationInspect)(
                        ...$this->applicationIdentityInput(
                            $body
                        )
                    )
                );
            }

            if (
                $route
                === '/v1/operator/provider/applications/stop'
            ) {
                if ($this->providerApplicationStop === null) {
                    throw ApiError::notFound(
                        'NOT_FOUND',
                        'Route not offered by this process'
                    );
                }

                return HttpResponse::json(
                    ($this->providerApplicationStop)(
                        ...$this->applicationIdentityInput(
                            $body
                        )
                    )
                );
            }

            if ($route === '/v1/operator/provider/capability') {
                if ($this->providerCapability === null) {
                    throw ApiError::notFound('NOT_FOUND', 'Route not offered by this process');
                }
                return HttpResponse::json(($this->providerCapability)($this->capabilityInput($body)));
            }
            if (strlen($body) > 0 && $body !== '{}') {
                throw ApiError::invalidRequest('This endpoint takes no request body');
            }
            $handler = match ($route) {
                '/v1/operator/provider/drain' => $this->providerDrain,
                '/v1/operator/provider/resume' => $this->providerResume,
                default => throw ApiError::notFound('NOT_FOUND', 'Unknown operator route'),
            };
            if ($handler === null) {
                throw ApiError::notFound('NOT_FOUND', 'Route not offered by this process');
            }
            return HttpResponse::json($handler());
        }
        throw ApiError::notFound('NOT_FOUND', 'Unknown operator route');
    }

    private function applicationStartInput(
        string $body
    ): array {
        $data =
            $this->applicationObject(
                $body
            );

        $allowed = [
            'application_id',
            'service',
            'image_ref',
            'pull_token',
            'registry_scheme',
        ];

        foreach ($data as $name => $value) {
            if (!in_array($name, $allowed, true)) {
                throw ApiError::invalidRequest(
                    'Unknown application start field',
                    [
                        'field' =>
                            (string) $name,
                    ]
                );
            }
        }

        foreach ($allowed as $name) {
            if (!array_key_exists($name, $data)) {
                throw ApiError::invalidRequest(
                    'Missing application start field',
                    [
                        'field' => $name,
                    ]
                );
            }
        }

        [$applicationId, $service] =
            $this->applicationIdentity(
                $data
            );

        $imageRef =
            $data['image_ref'];

        if (
            !is_string($imageRef)
            || $imageRef === ''
            || strlen($imageRef) > 4096
            || str_contains($imageRef, "\0")
            || !preg_match(
                '/@sha256:[a-f0-9]{64}$/D',
                $imageRef
            )
        ) {
            throw ApiError::invalidRequest(
                'image_ref must be digest-qualified'
            );
        }

        $pullToken =
            $data['pull_token'];

        if (
            !is_string($pullToken)
            || $pullToken === ''
            || strlen($pullToken) > 8192
            || str_contains($pullToken, "\0")
            || str_contains($pullToken, "\r")
            || str_contains($pullToken, "\n")
        ) {
            throw ApiError::invalidRequest(
                'Invalid registry pull credential'
            );
        }

        $scheme =
            $data['registry_scheme'];

        if (
            !is_string($scheme)
            || !in_array(
                $scheme,
                [
                    'http',
                    'https',
                ],
                true
            )
        ) {
            throw ApiError::invalidRequest(
                'registry_scheme must be http or https'
            );
        }

        return [
            $applicationId,
            $service,
            $imageRef,
            new RegistryTransferAuth(
                $pullToken,
                $scheme
            ),
        ];
    }

    private function applicationIdentityInput(
        string $body
    ): array {
        $data =
            $this->applicationObject(
                $body
            );

        $allowed = [
            'application_id',
            'service',
        ];

        foreach ($data as $name => $value) {
            if (!in_array($name, $allowed, true)) {
                throw ApiError::invalidRequest(
                    'Unknown application identity field',
                    [
                        'field' =>
                            (string) $name,
                    ]
                );
            }
        }

        foreach ($allowed as $name) {
            if (!array_key_exists($name, $data)) {
                throw ApiError::invalidRequest(
                    'Missing application identity field',
                    [
                        'field' => $name,
                    ]
                );
            }
        }

        return $this->applicationIdentity(
            $data
        );
    }

    private function applicationIdentity(
        array $data
    ): array {
        $applicationId =
            $data['application_id']
                ?? null;

        if (
            !is_int($applicationId)
            || $applicationId < 1
            || $applicationId > 0xffffffff
        ) {
            throw ApiError::invalidRequest(
                'Invalid application_id'
            );
        }

        $service =
            $data['service']
                ?? null;

        if (
            !is_string($service)
            || !preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D',
                $service
            )
        ) {
            throw ApiError::invalidRequest(
                'Invalid application service'
            );
        }

        return [
            $applicationId,
            $service,
        ];
    }

    private function applicationObject(
        string $body
    ): array {
        if (strlen($body) > self::MAX_BODY) {
            throw ApiError::invalidRequest(
                'Request body too large'
            );
        }

        $data =
            json_decode(
                $body,
                true,
                8
            );

        if (
            !is_array($data)
            || array_is_list($data)
        ) {
            throw ApiError::invalidRequest(
                'Expected a JSON object'
            );
        }

        return $data;
    }

    /** Request-shape validation only; protocol validation stays in the state machine. */
    private function capabilityInput(string $body): array
    {
        if (strlen($body) > self::MAX_BODY) {
            throw ApiError::invalidRequest('Request body too large');
        }
        $data = json_decode($body, true, 8);
        if (!is_array($data) || array_is_list($data)) {
            throw ApiError::invalidRequest('Expected a JSON object');
        }
        $allowed = ['status', 'architectures', 'cpu_capacity_millis', 'memory_capacity_bytes', 'pid_capacity', 'max_concurrent_jobs'];
        foreach ($data as $name => $value) {
            if (!in_array($name, $allowed, true)) {
                throw ApiError::invalidRequest('Unknown capability field', ['field' => (string) $name]);
            }
        }
        if (isset($data['status']) && !in_array($data['status'], ['ACTIVE', 'DRAINING', 'SUSPENDED'], true)) {
            throw ApiError::invalidRequest('status must be ACTIVE, DRAINING or SUSPENDED');
        }
        if (isset($data['architectures'])) {
            if (!is_array($data['architectures']) || $data['architectures'] === [] || count($data['architectures']) > 16) {
                throw ApiError::invalidRequest('architectures must be a non-empty list');
            }
            foreach ($data['architectures'] as $arch) {
                if (!is_string($arch)) {
                    throw ApiError::invalidRequest('architectures must be strings');
                }
            }
        }
        foreach (['cpu_capacity_millis', 'memory_capacity_bytes', 'pid_capacity', 'max_concurrent_jobs'] as $name) {
            if (isset($data[$name]) && (!is_int($data[$name]) || $data[$name] < 1)) {
                throw ApiError::invalidRequest($name . ' must be a positive integer');
            }
        }
        return $data;
    }
}
