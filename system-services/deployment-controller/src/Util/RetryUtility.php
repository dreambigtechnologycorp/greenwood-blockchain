<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Util;
/** Legacy callers get exactly one attempt; recovery requires durable reconciliation. */
final class RetryUtility
{
    public function execute(callable $operation, string $operationName): mixed { return $operation(); }
}
