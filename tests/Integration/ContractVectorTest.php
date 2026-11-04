<?php
declare(strict_types=1);
namespace Greenwood\Tests\Integration;
use PHPUnit\Framework\TestCase;
use Greenwood\Tests\Fixtures\{ContractExecutionVectors,ContractFixture as F};
use Greenwood\Transaction\Envelope;
final class ContractVectorTest extends TestCase
{
    public function testFrozenExecutionVectors(): void
    {
        $expected=json_decode(file_get_contents(__DIR__.'/../Fixtures/contracts-v1.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertSame($expected,ContractExecutionVectors::values());
        foreach ($expected['cases'] as $case) { $tx=Envelope::decode(hex2bin($case['transaction'])); $tx->verify(F::CHAIN); self::assertSame($case['transaction_hash'],bin2hex($tx->getHash())); }
    }
}
