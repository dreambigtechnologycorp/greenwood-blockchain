<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

final class ContractLimits
{
    public const MAX_INPUT_BYTES = 65536;
    public const MAX_STORAGE_KEY_BYTES = 256;
    public const MAX_STORAGE_VALUE_BYTES = 65536;
    public const MAX_RETURN_BYTES = 65536;
    public const MAX_EVENT_TOPIC_BYTES = 256;
    public const MAX_EVENT_DATA_BYTES = 4096;
    public const MAX_EVENTS_PER_CALL = 64;
    public const MAX_HOST_TRANSFER_OPERATIONS = 64;
    public const MAX_STORAGE_KEYS_PER_CALL = 256;
    public const MAX_HOST_CALLS = 4096;
    public const MAX_GAS = 1000000000;
}
