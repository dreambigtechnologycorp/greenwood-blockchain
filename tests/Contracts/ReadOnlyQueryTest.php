<?php
declare(strict_types=1);
namespace Greenwood\Tests\Contracts;

use Greenwood\Codec\Canonical as C;
use Greenwood\Contracts\{ContractId, ContractTransactionExecutor};
use Greenwood\Tests\Fixtures\ContractFixture as F;
use PHPUnit\Framework\TestCase;

final class ReadOnlyQueryTest extends TestCase
{
    public function testQueryReturnsCanonicalResultWithoutCommittingWrites(): void
    {
        $state = F::state();
        $state->applyTransaction(F::deploy($state, 'reference_storage'), F::CHAIN);
        $state->applyTransaction(F::call($state, 'reference_storage', 'set', C::encodeBytes('key').C::encodeBytes('original')), F::CHAIN);
        $id = ContractId::fromWasm(F::wasm('reference_storage'))->bytes;
        $before = clone $state;
        $result = ContractTransactionExecutor::query($state, $id, 'get', C::encodeBytes('key'));
        self::assertTrue($result->runtime->isSuccess());
        self::assertSame('original', $result->overlay->effects()['returnValue']);
        $write = ContractTransactionExecutor::query($state, $id, 'set', C::encodeBytes('key').C::encodeBytes('uncommitted'));
        self::assertTrue($write->runtime->isSuccess());
        self::assertSame('original', $state->getContractStorage($id, 'key'));
        self::assertEquals($before, $state);
    }
}
