<?php

declare(strict_types=1);

namespace Greenwood\Tests\Compute\Containerd;

use Containerd\Services\Images\V1\Image;
use Containerd\Services\Images\V1\ImagesClient;
use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\ContainerdConnection;
use Greenwood\Compute\Containerd\Service\ImagePreparationService;
use Greenwood\Compute\Containerd\Service\ImageService;
use Greenwood\Contracts\ContainerdRpcException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class ImageServiceExceptionContractTest extends TestCase
{
    public function testGetPreservesRpcFailureSubtypeAndCode(): void
    {
        if (!defined('Grpc\\STATUS_OK')) {
            self::markTestSkipped('The gRPC extension is required for the image RPC status contract.');
        }

        $matrix = [
            [
                ContainerdRpcException::RPC_NOT_FOUND,
                'image not found',
            ],
            [
                ContainerdRpcException::RPC_UNAVAILABLE,
                'containerd unavailable',
            ],
            [
                ContainerdRpcException::RPC_PERMISSION_DENIED,
                'permission denied',
            ],
            [
                ContainerdRpcException::RPC_INVALID_ARGUMENT,
                'invalid argument',
            ],
        ];

        foreach ($matrix as [$code, $details]) {
            $service = $this->imageServiceReturningStatus(
                $code,
                $details
            );

            try {
                $service->get(
                    'docker.io/greenwood/exception-contract:test'
                );

                self::fail(
                    'Expected ContainerdRpcException for RPC code '
                    . $code
                );
            } catch (ContainerdRpcException $e) {
                self::assertSame(
                    ContainerdRpcException::class,
                    $e::class
                );

                self::assertSame(
                    $code,
                    $e->getCode()
                );

                self::assertStringContainsString(
                    $details,
                    $e->getMessage()
                );
            }
        }
    }

    public function testPreparationRejectsNonNotFoundRpcFailuresWithoutTransfer(): void
    {
        $codes = [
            ContainerdRpcException::RPC_UNAVAILABLE,
            ContainerdRpcException::RPC_PERMISSION_DENIED,
            ContainerdRpcException::RPC_INVALID_ARGUMENT,
        ];

        foreach ($codes as $code) {
            $clients = $this->getMockBuilder(
                ContainerdClientSet::class
            )
                ->disableOriginalConstructor()
                ->onlyMethods(['getTransfer'])
                ->getMock();

            $clients
                ->expects(self::never())
                ->method('getTransfer');

            $preparation = new ImagePreparationService(
                $clients
            );

            $error = new ContainerdRpcException(
                'synthetic non-miss RPC failure',
                $code
            );

            $imageService = new class($error)
                extends ImageService
            {
                public function __construct(
                    private readonly ContainerdRpcException $error
                ) {
                }

                public function get(
                    string $reference
                ): Image {
                    throw $this->error;
                }
            };

            $property = new ReflectionProperty(
                ImagePreparationService::class,
                'imageService'
            );

            $property->setValue(
                $preparation,
                $imageService
            );

            try {
                $preparation->ensureReady(
                    'docker.io/greenwood/exception-contract:test'
                );

                self::fail(
                    'Non-NOT_FOUND RPC failure entered transfer path'
                );
            } catch (ContainerdRpcException $e) {
                self::assertSame(
                    $error,
                    $e
                );

                self::assertSame(
                    $code,
                    $e->getCode()
                );
            }
        }
    }

    private function imageServiceReturningStatus(
        int $code,
        string $details
    ): ImageService {
        $connection = $this->getMockBuilder(
            ContainerdConnection::class
        )
            ->disableOriginalConstructor()
            ->onlyMethods(['getMetadata'])
            ->getMock();

        $connection
            ->method('getMetadata')
            ->willReturn([
                'containerd-namespace' => ['greenwood'],
            ]);

        $call = new class($code, $details)
        {
            public function __construct(
                private readonly int $code,
                private readonly string $details
            ) {
            }

            public function wait(): array
            {
                return [
                    null,
                    (object) [
                        'code' => $this->code,
                        'details' => $this->details,
                    ],
                ];
            }
        };

        $images = $this->getMockBuilder(
            ImagesClient::class
        )
            ->disableOriginalConstructor()
            ->onlyMethods(['Get'])
            ->getMock();

        $images
            ->method('Get')
            ->willReturn($call);

        $clients = $this->getMockBuilder(
            ContainerdClientSet::class
        )
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getConnection',
                'getImages',
            ])
            ->getMock();

        $clients
            ->method('getConnection')
            ->willReturn($connection);

        $clients
            ->method('getImages')
            ->willReturn($images);

        return new ImageService($clients);
    }
}
