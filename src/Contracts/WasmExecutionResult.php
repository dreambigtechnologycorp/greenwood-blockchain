<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

class WasmExecutionResult
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FUEL_EXHAUSTED = 'fuel_exhausted';
    public const STATUS_TRAP = 'trap';
    public const STATUS_ERROR = 'error';
    public const STATUS_MISSING_EXPORT = 'missing_export';

    private string $status;
    private $returnValue;
    private ?int $fuelConsumed;
    private ?string $errorMessage;

    public function __construct(
        string $status,
        $returnValue = null,
        ?int $fuelConsumed = null,
        ?string $errorMessage = null
    ) {
        $this->status = $status;
        $this->returnValue = $returnValue;
        $this->fuelConsumed = $fuelConsumed;
        $this->errorMessage = $errorMessage;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getReturnValue()
    {
        return $this->returnValue;
    }

    public function getFuelConsumed(): ?int
    {
        return $this->fuelConsumed;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isFuelExhausted(): bool
    {
        return $this->status === self::STATUS_FUEL_EXHAUSTED;
    }

    public function isTrap(): bool
    {
        return $this->status === self::STATUS_TRAP;
    }

    public function isError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    public static function success($returnValue = null, ?int $fuelConsumed = null): self
    {
        return new self(self::STATUS_SUCCESS, $returnValue, $fuelConsumed);
    }

    public static function fuelExhausted(?int $fuelConsumed = null): self
    {
        return new self(self::STATUS_FUEL_EXHAUSTED, null, $fuelConsumed);
    }

    public static function trap(string $errorMessage, ?int $fuelConsumed = null): self
    {
        return new self(self::STATUS_TRAP, null, $fuelConsumed, $errorMessage);
    }

    public static function error(string $errorMessage, ?int $fuelConsumed = null): self
    {
        return new self(self::STATUS_ERROR, null, $fuelConsumed, $errorMessage);
    }

    public static function missingExport(string $exportName): self
    {
        return new self(self::STATUS_MISSING_EXPORT, null, null, "Export '$exportName' not found");
    }
}
