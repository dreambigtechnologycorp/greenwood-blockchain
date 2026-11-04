<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

interface WasmEngineInterface
{
    public function validate(string $wasm): void;
    public function execute(string $wasm, string $entrypoint, int $fuelLimit): WasmExecutionResult;
    public function getVersion(): string;
}
