<?php
declare(strict_types=1);
namespace Greenwood\Tests\Fixtures;
use Greenwood\Codec\Canonical as C;
use Greenwood\Contracts\{ContractId,ContractTransactionException};
use Greenwood\Transaction\{DeployContract,CallContract};
final class ContractExecutionVectors
{
    public static function values(): array
    {
        $s=ContractFixture::state();
        $records=static fn($state)=>array_map(fn($r)=>['namespace'=>$r[0],'key'=>bin2hex($r[1]),'value'=>bin2hex($r[2])],$state->logicalRecords());
        $steps=[
            ['deploy_storage','reference_storage','deploy','',10,'',100000],
            ['set','reference_storage','call','set',5,C::encodeBytes('x').C::encodeBytes('value'),100000],
            ['get','reference_storage','call','get',0,C::encodeBytes('x'),100000],
            ['delete','reference_storage','call','delete',0,C::encodeBytes('x'),100000],
            ['deploy_value','reference_value','deploy','',25,'',100000],
            ["pay","reference_value","call","pay",5,ContractFixture::address(5).C::encodeU64BE(7),100000],
            ['balance','reference_value','call','balance',3,'',100000],
            ['trap','reference_value','call','unknown',10,'',100000],
            ['out_of_gas','reference_value','call','balance',10,'',0],
            ['duplicate','reference_storage','deploy','',10,'',100000],
            ['init','init_storage','deploy','',11,'constructed',100000],
            ['init_trap','init_rollback_trap','deploy','',10,'',100000],
        ];
        $cases=[];
        foreach ($steps as [$name,$fixture,$type,$method,$value,$input,$gas]) {
            $wasm=ContractFixture::wasm($fixture); $id=ContractId::fromWasm($wasm)->bytes;
            $payload=$type==='deploy'?new DeployContract($wasm,$input,$value,$gas):new CallContract($id,$method,$input,$value,$gas);
            $tx=$payload->createEnvelope(ContractFixture::CHAIN,ContractFixture::address(),$s->getAccount(ContractFixture::address())->getNonce(),ContractFixture::key());
            $pre=$records($s); $preRoot=bin2hex($s->getStateRoot());
            try { $s->applyTransaction($tx,ContractFixture::CHAIN); $r=$s->getContractReceipt($tx->getHash()); }
            catch (ContractTransactionException $e) { $r=$e->receipt; }
            $cases[]=['name'=>$name,'fixture'=>$fixture,'type'=>$type,'method'=>$method,'calldata'=>bin2hex($input),'attached_value'=>$value,'gas_limit'=>$gas,
                'wasm'=>bin2hex($wasm),'contract_id'=>bin2hex($id),'wasm_hash'=>bin2hex($id),'pre_state'=>$pre,'pre_root'=>$preRoot,
                'payload'=>bin2hex($payload->serialize()),'unsigned_transaction'=>bin2hex($tx->getSigningBytes()),'transaction'=>bin2hex($tx->getCanonicalBytes()),
                'signing_digest'=>bin2hex($tx->getSigningDigest()),'transaction_hash'=>bin2hex($tx->getHash()),'receipt'=>$r->toArray(),'post_state'=>$records($s),'post_root'=>bin2hex($s->getStateRoot())];
        }
        return ['version'=>1,'chain_id'=>ContractFixture::CHAIN,'seed_recipe'=>'Sender: 32 repetitions of 04 hex; recipient: 32 repetitions of 05 hex.',
            'sender_public_key'=>bin2hex(ContractFixture::key()->getPublicKey()),'sender'=>bin2hex(ContractFixture::address()),'cases'=>$cases];
    }
}
