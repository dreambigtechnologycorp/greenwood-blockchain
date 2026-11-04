<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

use Greenwood\State\State;
final class ContractStateCommitter
{
    public static function apply(State $state,ExecutionOverlay $overlay,?string $deployWasm=null): void
    {
        if (!$overlay->isSealed()) throw new \InvalidArgumentException('Successful sealed overlay required');
        $next=clone $state; $id=$overlay->context->contractId;
        if ($deployWasm!==null) $next->setContractCode($id,$deployWasm,$id);
        if ($next->getContractCode($id)===null) throw new \InvalidArgumentException('Contract does not exist');
        $effects=$overlay->effects();
        foreach ($effects['storageDeletes'] as $key=>$unused) $next->deleteContractStorage($id,substr($key,1));
        foreach ($effects['storageWrites'] as $key=>$value) $next->setContractStorage($id,substr($key,1),$value);
        foreach ($effects['accountBalanceDeltas'] as $key=>$delta) {
            $address=substr($key,1); $balance=$next->hasAccount($address)?$next->getAccount($address)->getBalance():0;
            $next->setAccountBalance($address,self::add($balance,$delta));
        }
        foreach ($effects['contractBalanceDeltas'] as $key=>$delta) {
            $contract=substr($key,1);
            if ($contract!==$id) throw new \InvalidArgumentException('Cross-contract overlay');
            $next->setContractBalance($id,self::add($next->getContractBalance($id),$delta));
        }
        $next->recomputeStateRoot();
        // Only publish after every operation and root computation succeeded.
        $state->copyFrom($next);
    }
    private static function add(int $balance,int $delta): int
    {
        if (($delta>0 && $balance>PHP_INT_MAX-$delta) || ($delta<0 && $delta<-$balance)) throw new \InvalidArgumentException('Illegal overlay balance');
        return $balance+$delta;
    }
}
