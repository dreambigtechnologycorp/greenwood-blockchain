<?php

declare(strict_types=1);

namespace Greenwood\Tests\Compute\Containerd;

use Greenwood\Compute\Containerd\RegistryTransferAuth;
use PHPUnit\Framework\TestCase;

final class RegistryTransferAuthTest extends TestCase
{
    public function testBearerHeaderAndScheme(): void
    {
        $auth = new RegistryTransferAuth(
            'greenwood-token',
            'http'
        );

        self::assertSame(
            'Bearer greenwood-token',
            $auth->authorizationHeader()
        );

        self::assertSame(
            'http',
            $auth->scheme()
        );
    }

    public function testHttpsIsDefault(): void
    {
        $auth = new RegistryTransferAuth(
            'greenwood-token'
        );

        self::assertSame(
            'https',
            $auth->scheme()
        );
    }

    public function testEmptyTokenIsRejected(): void
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        new RegistryTransferAuth('');
    }

    public function testHeaderInjectionIsRejected(): void
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        new RegistryTransferAuth(
            "valid\r\nX-Evil: value"
        );
    }

    public function testUnsupportedSchemeIsRejected(): void
    {
        $this->expectException(
            \InvalidArgumentException::class
        );

        new RegistryTransferAuth(
            'greenwood-token',
            'ftp'
        );
    }
}
