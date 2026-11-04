<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\State;
interface OperationStore {
    public function accept(string $account,array $intent):array;
    public function get(string $id):array;
    public function byKey(string $account,string $key):?array;
    public function save(array $operation,string $state,array $evidence=[]):array;
    public function exclusive(string $id,\Closure $work):mixed;
}
