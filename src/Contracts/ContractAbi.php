<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

/** Value kinds match WASM i32=0 / i64=1, translated to binary policy types there. */
final class ContractAbi
{
    public const FUNCTIONS = [
        'input_size'=>[[],0],
        'input_read'=>[[0,0],0],
        'caller'=>[[0],0],
        'contract_id'=>[[0],0],
        'storage_get'=>[[0,0,0,0],0],
        'storage_set'=>[[0,0,0,0],0],
        'storage_delete'=>[[0,0],0],
        'attached_value'=>[[],1],
        'contract_balance'=>[[],1],
        'transfer'=>[[0,1],0],
        'emit_event'=>[[0,0,0,0],0],
        'return_value'=>[[0,0],0],
    ];
}
