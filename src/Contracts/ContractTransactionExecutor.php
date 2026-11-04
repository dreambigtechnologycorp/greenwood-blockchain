<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

use Greenwood\State\State;
use Greenwood\Transaction\{Envelope,DeployContract,CallContract};
final class ContractTransactionExecutor
{
    public static function apply(State $state,Envelope $tx): ContractReceipt
    {
        $deploy=$tx->getType()===Envelope::TYPE_DEPLOY_CONTRACT;
        $payload=$deploy?DeployContract::decode($tx->getPayload()):CallContract::decode($tx->getPayload());
        $id=$deploy?ContractId::fromWasm($payload->wasm)->bytes:$payload->contractId;
        $hash=$tx->getHash();
        $fail=static function(string $status,int $gas=0) use ($id,$hash): never {
            throw new ContractTransactionException(new ContractReceipt($status,$gas,'',[],$id,$hash));
        };
        $code=$state->getContractCode($id);
        if ($deploy && $code!==null) $fail('CONTRACT_ALREADY_EXISTS');
        if (!$deploy && $code===null) $fail('CONTRACT_NOT_FOUND');
        $wasm=$deploy?$payload->wasm:$code['wasm'];
        $engine=new WasmtimeEngine();
        try { $engine->validateContract($wasm); } catch (WasmValidationException) { $fail('INVALID_WASM'); }
        $input=$deploy?$payload->initCalldata:$payload->invocationInput();
        $context=new ContractExecutionContext($tx->getSender(),$id,$input,$payload->attachedValue,$payload->gasLimit);
        $view=new ContractStateView($state);
        if ($view->accountBalance($context->caller)<$context->attachedValue) $fail('INSUFFICIENT_BALANCE');
        if ($view->contractBalance($id)>PHP_INT_MAX-$context->attachedValue) $fail('BALANCE_OVERFLOW');
        $result=$deploy?$engine->executeDeployment($wasm,$context,$view):$engine->executeContract($wasm,$context,$view);
        $gas=$result->runtime->getFuelConsumed()??0;
        if (!$result->runtime->isSuccess()) $fail(match ($result->runtime->getStatus()) {
            'trap'=>'TRAP','fuel_exhausted'=>'OUT_OF_GAS','missing_export'=>'MISSING_EXPORT',default=>'EXECUTION_ERROR',
        },$gas);
        ContractStateCommitter::apply($state,$result->overlay,$deploy?$wasm:null);
        $effects=$result->overlay->effects();
        return new ContractReceipt('SUCCESS',$gas,$effects['returnValue'],
            array_map(fn(ContractEvent $e)=>new ContractTransactionEvent($id,$e->topic,$e->data,$hash,$e->eventIndex),$effects['events']),$id,$hash);
    }

    /**
     * Read-only contract execution against finalized state.
     *
     * Runs the contract method through greenwood_call with the encoded
     * method+calldata input. The overlay is returned for result inspection but never committed —
     * no state change, no transaction, no consensus.
     *
     * @param  State  $state    Finalized (or cloned) chain state.
     * @param  string $contractId 32-byte contract ID.
     * @param  string $method     Method name to dispatch within the contract.
     * @param  string $calldata   Raw bytes passed to the method.
     * @param  int    $gasLimit   Internal computation cap (defaults to MAX_GAS).
     * @return ContractExecutionResult
     */
    public static function query(
        State $state,
        string $contractId,
        string $method,
        string $calldata,
        int $gasLimit = ContractLimits::MAX_GAS,
    ): ContractExecutionResult {
        if (strlen($contractId) !== 32) {
            throw new \InvalidArgumentException('Contract ID must be 32 bytes');
        }
        if ($gasLimit < 0 || $gasLimit > ContractLimits::MAX_GAS) {
            throw new \InvalidArgumentException('Gas limit out of range');
        }

        $code = $state->getContractCode($contractId);
        if ($code === null) {
            throw new \InvalidArgumentException('Contract not found');
        }

        $wasm = $code['wasm'];
        $input = CallContract::invocationInputStatic($method, $calldata);
        // Read-only caller: 32 zero bytes (no real sender needed for a query).
        $caller = str_repeat("\0", 32);
        $context = new ContractExecutionContext($caller, $contractId, $input, 0, $gasLimit);
        $view = new ContractStateView($state);

        $engine = new WasmtimeEngine();
        try {
            $engine->validateContract($wasm);
        } catch (WasmValidationException $e) {
            throw new \RuntimeException('Contract validation failed: ' . $e->getMessage(), 0, $e);
        }

        $result = $engine->executeContract($wasm, $context, $view);
        // Preserve return data for RPC inspection; never commit query effects.
        return $result;
    }
}
