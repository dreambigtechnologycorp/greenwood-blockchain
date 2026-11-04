<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

final readonly class ContractExecutionResult
{
    public function __construct(public WasmExecutionResult $runtime,public ExecutionOverlay $overlay) {}
}
