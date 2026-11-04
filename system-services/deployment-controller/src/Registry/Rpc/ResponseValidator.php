<?php

declare(strict_types=1);
namespace Greenwood\DeploymentController\Registry\Rpc;

final class ResponseValidator
{
    public static function contracts(): array
    {
        static $schemas;
        return $schemas ??= json_decode(file_get_contents(__DIR__ . '/contract.json'), true, 64, JSON_THROW_ON_ERROR);
    }

    public static function validate(mixed $value, mixed $schema, string $path = '$'): void
    {
        if (is_array($schema)) {
            if (!is_array($value)) throw new \UnexpectedValueException("$path must be an object/list");
            if (array_is_list($schema)) {
                if (!array_is_list($value)) throw new \UnexpectedValueException("$path must be a list");
                foreach ($value as $i => $item) self::validate($item, $schema[0], "$path.$i");
            } else {
                foreach ($schema as $key => $type) {
                    if (!array_key_exists($key, $value)) throw new \UnexpectedValueException("$path.$key is required");
                    self::validate($value[$key], $type, "$path.$key");
                }
            }
            return;
        }
        if (str_starts_with($schema, '?')) {
            if ($value === null) return;
            $schema = substr($schema, 1);
        }
        $valid = match (true) {
            $schema === 'uint' => is_int($value) && $value >= 0,
            $schema === 'int' => is_int($value),
            $schema === 'bool' => is_bool($value),
            $schema === 'string' => is_string($value),
            $schema === 'nonempty' => is_string($value) && $value !== '',
            $schema === 'object' => is_array($value),
            $schema === 'decimal' => is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value),
            $schema === 'hex64' => is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value),
            $schema === 'hex' => is_string($value) && preg_match('/^(?:[0-9a-f]{2})*$/D', $value),
            str_starts_with($schema, 'enum:') => in_array($value, explode(',', substr($schema, 5)), true),
            default => false,
        };
        if (!$valid) throw new \UnexpectedValueException("$path must be $schema");
    }

    public static function result(string $operation, array $data): array
    {
        self::validate($data, self::contracts()[$operation]['schema']);
        if ($operation === 'callContract') {
            if ($data['status'] === 'SUCCESS') {
                self::validate($data, ['return_data' => 'hex']);
                $data['return_bytes'] = hex2bin($data['return_data']);
            } else {
                self::validate($data, ['error' => '?string']);
                $data['return_bytes'] = null;
            }
        }
        if (in_array($operation, ['getTransaction', 'getTransactionStatus'], true)) {
            if ($data['status'] === 'FINALIZED') {
                self::validate($data, ['height' => 'uint', 'block_hash' => 'hex64']);
                if ($operation === 'getTransaction') self::validate($data, ['type'=>'uint','sender'=>'hex64','sender_public_key'=>'hex64','nonce'=>'uint','finalized_height'=>'uint']);
            } elseif ($operation === 'getTransaction') {
                if ($data['status'] !== 'PENDING') throw new \UnexpectedValueException('Unknown transaction detail must be an HTTP error');
                self::validate($data, ['height'=>'?uint','block_hash'=>'?hex64']);
                if ($data['height'] !== null || $data['block_hash'] !== null) throw new \UnexpectedValueException('Pending transaction cannot have finalized location');
            }
        }
        return $data;
    }
}
