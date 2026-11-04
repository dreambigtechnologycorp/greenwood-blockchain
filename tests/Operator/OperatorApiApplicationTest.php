<?php

declare(strict_types=1);

namespace Greenwood\Tests\Operator;

use Greenwood\Compute\Containerd\RegistryTransferAuth;
use Greenwood\Rpc\OperatorApi;
use Greenwood\Rpc\OperatorAuth;
use PHPUnit\Framework\TestCase;

final class OperatorApiApplicationTest extends TestCase
{
    private string $directory;
    private string $operatorToken;
    private string $tokenFile;
    private array $headers;

    protected function setUp(): void
    {
        $this->directory =
            sys_get_temp_dir()
            . '/greenwood-operator-app-'
            . bin2hex(random_bytes(8));

        mkdir(
            $this->directory,
            0700,
            true
        );

        $this->operatorToken =
            str_repeat(
                'o',
                32
            );

        $this->tokenFile =
            $this->directory
            . '/operator.token';

        file_put_contents(
            $this->tokenFile,
            $this->operatorToken
        );

        chmod(
            $this->tokenFile,
            0600
        );

        $this->headers = [
            'authorization' =>
                'Bearer '
                . $this->operatorToken,
        ];
    }

    protected function tearDown(): void
    {
        @unlink(
            $this->tokenFile
        );

        @rmdir(
            $this->directory
        );
    }

    private function api(
        ?\Closure $start = null,
        ?\Closure $inspect = null,
        ?\Closure $stop = null
    ): OperatorApi {
        return new OperatorApi(
            OperatorAuth::fromFile(
                $this->tokenFile
            ),
            providerApplicationStart:
                $start,
            providerApplicationInspect:
                $inspect,
            providerApplicationStop:
                $stop
        );
    }

    private function post(
        OperatorApi $api,
        string $path,
        array $body,
        ?array $headers = null
    ): array {
        $response =
            $api->handle(
                'POST',
                $path,
                json_encode(
                    $body,
                    JSON_THROW_ON_ERROR
                ),
                $headers
                    ?? $this->headers
            );

        return [
            $response->status,
            json_decode(
                $response->body,
                true,
                32,
                JSON_THROW_ON_ERROR
            ),
        ];
    }

    private function startBody(): array
    {
        return [
            'application_id' => 42,
            'service' => 'Web.API',
            'image_ref' =>
                'registry.local/apps/42/web@sha256:'
                . str_repeat(
                    'a',
                    64
                ),
            'pull_token' =>
                'registry-pull-'
                . str_repeat(
                    'p',
                    32
                ),
            'registry_scheme' => 'http',
        ];
    }

    public function testApplicationRoutesRequireOperatorAuth(): void
    {
        $called = false;

        $api =
            $this->api(
                start:
                    function () use (
                        &$called
                    ): array {
                        $called = true;

                        return [];
                    }
            );

        [$status] =
            $this->post(
                $api,
                '/v1/operator/provider/applications/start',
                $this->startBody(),
                []
            );

        self::assertSame(
            401,
            $status
        );

        self::assertFalse(
            $called
        );
    }

    public function testStartReceivesOnlyValidatedIdentityAndRegistryAuth(): void
    {
        $received = null;

        $api =
            $this->api(
                start:
                    function (
                        int $applicationId,
                        string $service,
                        string $imageRef,
                        RegistryTransferAuth $auth
                    ) use (
                        &$received
                    ): array {
                        $received = [
                            'application_id' =>
                                $applicationId,
                            'service' =>
                                $service,
                            'image_ref' =>
                                $imageRef,
                            'scheme' =>
                                $auth->scheme(),
                            'authorization' =>
                                $auth
                                    ->authorizationHeader(),
                        ];

                        return [
                            'state' =>
                                'RUNNING',
                        ];
                    }
            );

        $body =
            $this->startBody();

        [$status, $response] =
            $this->post(
                $api,
                '/v1/operator/provider/applications/start',
                $body
            );

        self::assertSame(
            200,
            $status
        );

        self::assertSame(
            'RUNNING',
            $response['state']
        );

        self::assertSame(
            42,
            $received['application_id']
        );

        self::assertSame(
            'Web.API',
            $received['service']
        );

        self::assertSame(
            $body['image_ref'],
            $received['image_ref']
        );

        self::assertSame(
            'http',
            $received['scheme']
        );

        self::assertSame(
            'Bearer '
                . $body['pull_token'],
            $received['authorization']
        );

        self::assertStringNotContainsString(
            $body['pull_token'],
            json_encode(
                $response,
                JSON_THROW_ON_ERROR
            )
        );
    }

    public function testExecutionControlFieldsAreRejected(): void
    {
        $forbidden = [
            'command' => [
                '/bin/sh',
            ],
            'args' => [
                '-c',
                'true',
            ],
            'executable' =>
                '/bin/sh',
            'shell' =>
                'true',
            'environment' => [
                'X' => '1',
            ],
            'working_directory' =>
                '/',
            'mounts' => [],
            'user' =>
                '0:0',
            'privileged' =>
                true,
            'host_network' =>
                true,
            'ports' => [
                8080,
            ],
        ];

        foreach (
            $forbidden
            as $field => $value
        ) {
            $called = false;

            $api =
                $this->api(
                    start:
                        function () use (
                            &$called
                        ): array {
                            $called = true;

                            return [];
                        }
                );

            $body =
                $this->startBody();

            $body[$field] =
                $value;

            [$status] =
                $this->post(
                    $api,
                    '/v1/operator/provider/applications/start',
                    $body
                );

            self::assertSame(
                400,
                $status,
                $field
            );

            self::assertFalse(
                $called,
                $field
            );
        }
    }

    public function testStartRequiresExactDigestQualifiedImage(): void
    {
        $api =
            $this->api(
                start:
                    static fn (): array =>
                        []
            );

        $body =
            $this->startBody();

        $body['image_ref'] =
            'registry.local/apps/42/web:latest';

        [$status] =
            $this->post(
                $api,
                '/v1/operator/provider/applications/start',
                $body
            );

        self::assertSame(
            400,
            $status
        );
    }

    public function testInspectAndStopAcceptIdentityOnly(): void
    {
        $events = [];

        $api =
            $this->api(
                inspect:
                    function (
                        int $applicationId,
                        string $service
                    ) use (
                        &$events
                    ): array {
                        $events[] = [
                            'inspect',
                            $applicationId,
                            $service,
                        ];

                        return [
                            'state' =>
                                'RUNNING',
                        ];
                    },
                stop:
                    function (
                        int $applicationId,
                        string $service
                    ) use (
                        &$events
                    ): array {
                        $events[] = [
                            'stop',
                            $applicationId,
                            $service,
                        ];

                        return [
                            'state' =>
                                'STOPPED',
                        ];
                    }
            );

        $identity = [
            'application_id' => 42,
            'service' => 'Web.API',
        ];

        [$inspectStatus] =
            $this->post(
                $api,
                '/v1/operator/provider/applications/inspect',
                $identity
            );

        [$stopStatus] =
            $this->post(
                $api,
                '/v1/operator/provider/applications/stop',
                $identity
            );

        self::assertSame(
            200,
            $inspectStatus
        );

        self::assertSame(
            200,
            $stopStatus
        );

        self::assertSame(
            [
                [
                    'inspect',
                    42,
                    'Web.API',
                ],
                [
                    'stop',
                    42,
                    'Web.API',
                ],
            ],
            $events
        );
    }

    public function testApplicationRoutesAreNotOfferedWithoutHandlers(): void
    {
        $api =
            $this->api();

        [$status] =
            $this->post(
                $api,
                '/v1/operator/provider/applications/start',
                $this->startBody()
            );

        self::assertSame(
            404,
            $status
        );
    }
}
