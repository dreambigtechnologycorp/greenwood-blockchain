<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Assignment;

/** Explicit protected configuration, never inferred from remote acknowledgements. */
final class ReceiverTopology
{
    public const LEGACY = ['node-1', 'node-4'];
    public const SEVEN_NODE = ['node-1', 'node-2', 'node-3', 'node-5', 'node-6', 'node-7'];

    public static function validate(array $nodes): array
    {
        if (!array_is_list($nodes)) throw new \InvalidArgumentException('INVALID_RECEIVER_TOPOLOGY');
        foreach ($nodes as $node) if (!is_string($node)) throw new \InvalidArgumentException('INVALID_RECEIVER_TOPOLOGY');
        sort($nodes, SORT_STRING);
        if ($nodes !== self::LEGACY && $nodes !== self::SEVEN_NODE) {
            throw new \InvalidArgumentException('INVALID_RECEIVER_TOPOLOGY');
        }
        return $nodes;
    }

    public static function fromConfig(array $config): array
    {
        $peers = $config['assignment_receivers'] ?? null;
        if (!is_array($peers)) throw new \InvalidArgumentException('INVALID_RECEIVER_TOPOLOGY');
        return self::validate(array_keys($peers));
    }
}
