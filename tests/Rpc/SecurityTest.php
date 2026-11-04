<?php
declare(strict_types=1);
namespace Greenwood\Tests\Rpc;

use Greenwood\Consensus\Runtime\ConsensusDriver;
use Greenwood\Crypto\Ed25519KeyPair;
use Greenwood\Genesis\Genesis;
use Greenwood\Node\Chain;
use Greenwood\Persistence\{BlockFilesystem, StateSqlite};
use Greenwood\Rpc\HttpApi;
use Greenwood\Tests\Fixtures\ComputeFixture as F;
use PHPUnit\Framework\TestCase;

/** Pass 5 final RPC security audit battery against the public /v1 surface. */
final class SecurityTest extends TestCase
{
    private HttpApi $api;
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gw-sec-' . bin2hex(random_bytes(6));
        $g = new Genesis('rpc-test');
        foreach (['B', 'C', 'D'] as $id) $g->addValidator($id, Ed25519KeyPair::generate(), 1);
        $g->addValidator('A', F::key(), 1);
        $g->addInitialAccount('provider', F::key(), 10000);
        $chain = new Chain($g, new BlockFilesystem($this->dir . '/blocks'), new StateSqlite($this->dir . '/state.sqlite'));
        $driver = new ConsensusDriver($chain, 'E', [], null, 'http://127.0.0.1:0');
        $this->api = (new \ReflectionProperty(ConsensusDriver::class, 'api'))->getValue($driver);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        rmdir($this->dir);
    }

    private function req(string $method, string $path, string $body = ''): array
    {
        $r = $this->api->handle($method, $path, $body);
        $decoded = json_decode($r->body, true);
        return [$r->status, $decoded, $r->body];
    }

    private function assertStableError(int $expected, array $response): void
    {
        [$status, $body, $raw] = $response;
        self::assertSame($expected, $status);
        self::assertIsArray($body);
        self::assertArrayHasKey('error', $body);
        self::assertArrayHasKey('code', $body['error']);
        self::assertArrayHasKey('message', $body['error']);
        self::assertArrayHasKey('details', $body['error']);
        // No stack traces, file paths or PHP internals ever leak.
        foreach (['Stack trace', '#0 ', '.php on line', 'Traceback', '\\Greenwood\\'] as $leak) {
            self::assertStringNotContainsString($leak, $raw, 'Leaked: ' . $leak);
        }
    }

    public function testMalformedHexIds(): void
    {
        foreach (['/v1/accounts/zz', '/v1/accounts/' . str_repeat('aa', 31), '/v1/accounts/' . str_repeat('aa', 33),
                  '/v1/transactions/not-hex', '/v1/blocks/hash/12zz', '/v1/compute/requests/!!',
                  '/v1/compute/providers/' . str_repeat('g', 64)] as $path) {
            $this->assertStableError(400, $this->req('GET', $path));
        }
    }

    public function testPathTraversalAndEncodingTricks(): void
    {
        foreach (['/v1/blocks/../status', '/v1/blocks/%2e%2e/status', '/v1/accounts//nonce',
                  '/v1/chain/status/../../etc/passwd', '//v1//chain//status', '/v1/chain/status%00',
                  '/v1/blocks/-1', '/v1/blocks/1x', '/v1/blocks/99999999999999999999'] as $path) {
            [$status, $body] = $this->req('GET', $path);
            self::assertContains($status, [400, 404], $path . ' must be rejected, got ' . $status);
            self::assertArrayHasKey('error', $body);
        }
    }

    public function testMethodConfusion(): void
    {
        foreach (['DELETE', 'PUT', 'PATCH'] as $method) {
            [$status] = $this->req($method, '/v1/chain/status');
            self::assertContains($status, [400, 404, 405]);
        }
        [$status] = $this->req('GET', '/v1/transactions');
        self::assertContains($status, [400, 404, 405]);
        [$status] = $this->req('POST', '/v1/chain/status', '{}');
        self::assertContains($status, [400, 404, 405]);
    }

    public function testMalformedJsonBodies(): void
    {
        foreach (['{', 'not json', '{"a":', str_repeat('[', 200), str_repeat('{"a":', 100), "\xFF\xFE garbage"] as $body) {
            [$status, $decoded, $raw] = $this->req('POST', '/v1/transactions', $body);
            self::assertContains($status, [400, 422]);
            self::assertNotNull($decoded, 'Error body must be valid JSON');
            self::assertArrayHasKey('error', $decoded);
        }
    }

    public function testTransactionSubmissionFieldValidation(): void
    {
        foreach ([[], ['tx' => 'zz-not-base64'], ['tx' => ''], ['transaction' => base64_encode('x')],
                  ['tx' => base64_encode(str_repeat('\x00', 8))]] as $payload) {
            [$status, $body] = $this->req('POST', '/v1/transactions', json_encode($payload));
            self::assertContains($status, [400, 422]);
            self::assertArrayHasKey('error', $body);
        }
    }

    public function testOperatorRoutesUnreachableOnPublicApi(): void
    {
        foreach (['/v1/operator/provider/status', '/v1/operator/provider/jobs', '/v1/operator/provider/drain',
                  '/v1/operator/provider/resume', '/v1/operator/provider/capability', '/v1/operator/validator/status',
                  '/v1/operator/validator/consensus', '/operator/provider/drain'] as $path) {
            [$status, $body] = $this->req('GET', $path);
            self::assertSame(404, $status, $path . ' must not exist on the public surface');
            [$status] = $this->req('POST', $path, '{}');
            self::assertSame(404, $status);
        }
    }

    public function testRouteClassificationSingleSource(): void
    {
        foreach (HttpApi::ROUTES as $route => $class) {
            self::assertContains($class, ['PUBLIC', 'AUTHENTICATED', 'OPERATOR_ONLY']);
            self::assertStringStartsNotWith('/v1/operator', explode(' ', $route)[1], 'Operator routes never registered publicly');
        }
    }

    public function testNoSecretMaterialInAnyResponse(): void
    {
        $paths = ['/v1/chain/status', '/v1/chain/finality', '/v1/chain/validators', '/v1/chain/state-root',
                  '/v1/accounts/' . bin2hex(F::requester()), '/v1/compute/providers', '/v1/health', '/v1/ready'];
        foreach ($paths as $path) {
            [, , $raw] = $this->req('GET', $path);
            self::assertStringNotContainsString(F::PROVIDER_PRIVATE_KEY, $raw);
            self::assertStringNotContainsString('private', strtolower($raw));
            self::assertStringNotContainsString('journal', strtolower($raw));
        }
    }

    public function testErrorSchemaMatchesFrozenModel(): void
    {
        [, $body] = $this->req('GET', '/v1/accounts/' . str_repeat('ab', 32));
        self::assertSame(['code', 'message', 'details'], array_keys($body['error']));
        self::assertSame(['error'], array_keys($body));
    }

    public function testPaginationBoundsEnforced(): void
    {
        [, $body] = $this->req('GET', '/v1/compute/providers?limit=999999');
        self::assertLessThanOrEqual(HttpApi::MAX_LIMIT, $body['limit'] ?? 0);
        $this->assertStableError(400, $this->req('GET', '/v1/compute/providers?limit=0'));
        $this->assertStableError(400, $this->req('GET', '/v1/compute/providers?limit=-5'));
        $this->assertStableError(400, $this->req('GET', '/v1/compute/providers?cursor=' . str_repeat('x', 500)));
    }
}
