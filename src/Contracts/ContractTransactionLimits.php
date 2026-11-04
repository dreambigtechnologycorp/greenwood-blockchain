<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

final class ContractTransactionLimits
{
    // Leave room for calldata, envelope and unchanged 1 MiB block framing.
    public const MAX_WASM_BYTES=524288;
    public const MAX_METHOD_BYTES=64;
    public const MAX_CALLDATA_BYTES=65464; // 65536 minus two u32 lengths and max method.
    public const MAX_TRANSACTION_BYTES=600000;
}
