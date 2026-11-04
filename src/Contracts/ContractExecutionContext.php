<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

final readonly class ContractExecutionContext
{
    public function __construct(
        public string $caller,
        public string $contractId,
        public string $input,
        public int $attachedValue,
        public int $gasLimit,
    ) {
        if (PHP_INT_SIZE !== 8 || strlen($caller) !== 32 || strlen($contractId) !== 32
            || strlen($input) > ContractLimits::MAX_INPUT_BYTES || $attachedValue < 0
            || $gasLimit < 0 || $gasLimit > ContractLimits::MAX_GAS) {
            throw new \InvalidArgumentException('Invalid contract execution context');
        }
    }
}
