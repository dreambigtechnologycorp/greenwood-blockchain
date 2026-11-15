<?php
declare(strict_types=1);
namespace Greenwood\Economics;

use Greenwood\Contracts\{ContractId,ContractTransactionExecutor};
use Greenwood\Compute\Protocol\ProviderId;
use Greenwood\State\State;

/** Deterministic adapter for the existing registry ABI and assignment-document format. */
final class HostedServiceBinding
{
    /** Existing deployment-controller assignment ID, distinct from full generation and financial commitment. */
    public static function assignmentId(array $document): string {
        $assignment=$document['assignment']??throw new \InvalidArgumentException('Hosted assignment required');ksort($assignment,SORT_STRING);return hash('sha256',json_encode($assignment,JSON_THROW_ON_ERROR));
    }
    public static function generation(array $document): string { return hash('sha256',CanonicalJson::encode($document)); }
    public static function hash(array $binding): string { return bin2hex(\Greenwood\Crypto\ProtocolHash::hash('GREENWOOD_HOSTED_BINDING_V1',CanonicalJson::encode($binding))); }
    public static function validate(array $h,array $p,string $chain,string $payer,int $height,int $end): array {
        $d=$h['document']??null;$a=$d['assignment']??null;$start=$h['start_height']??null;
        if(!is_array($d)||!is_array($a)||count($h)!==5||count($d)!==7||count($a)!==12||array_diff(array_keys($h),['document','start_height','cpu_budget_ms','max_payment_units','authority_epoch'])
            ||array_diff(array_keys($d),['chain_id','registry_contract_id','operation_id','account_id','version','previous_generation','assignment'])
            ||array_diff(array_keys($a),['chain_id','registry_contract_id','application_id','service','release_id','provider_id','provider_address','ingress_port','instance_id','image_digest','provider_endpoint','provider_tls_name']))throw new \InvalidArgumentException('Invalid hosted assignment framing');
        if(($d['chain_id']??null)!==$chain || ($a['chain_id']??null)!==$chain || ($d['registry_contract_id']??null)!==$p['registry_contract_id']
            ||($a['registry_contract_id']??null)!==$p['registry_contract_id'] || ($d['account_id']??null)!==$payer
            ||!is_string($d['operation_id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$d['operation_id'])
            ||!is_int($d['version']??null)||$d['version']<1||$d['version']>0xffffffff
            ||($d['version']===1?($d['previous_generation']??null)!==null:!is_string($d['previous_generation']??null)||!preg_match('/^[a-f0-9]{64}$/D',$d['previous_generation']))
            ||!is_int($a['application_id']??null)||$a['application_id']<1||$a['application_id']>0xffffffff
            ||!is_int($a['release_id']??null)||$a['release_id']<1||$a['release_id']>0xffffffff
            ||!is_string($a['service']??null)||!preg_match('/^[a-z][a-z0-9-]{0,62}$/D',$a['service'])
            ||($a['provider_id']??null)!==bin2hex(ProviderId::fromPublicKey(hex2bin($p['provider_key']))->toBytes())
            ||!is_string($a['instance_id']??null)||!preg_match('/^[a-z0-9-]{1,100}$/D',$a['instance_id'])
            ||!is_string($a['image_digest']??null)||!preg_match('/^sha256:[a-f0-9]{64}$/D',$a['image_digest'])
            ||!is_int($start)||$start<=$height||$end<=$start||$end-$start>43200
            ||!is_int($h['authority_epoch']??null)||$h['authority_epoch']<1)throw new \InvalidArgumentException('Hosted identity/interval mismatch');
        if(!is_string($a['provider_address']??null)||!filter_var($a['provider_address'],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)
            ||!preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/',$a['provider_address'])
            ||!is_int($a['ingress_port']??null)||$a['ingress_port']<10000||$a['ingress_port']>65535
            ||!is_string($a['provider_tls_name']??null)||!preg_match('/^[a-zA-Z0-9.-]{1,253}$/D',$a['provider_tls_name']))throw new \InvalidArgumentException('Hosted endpoint mismatch');
        $url=is_string($a['provider_endpoint']??null)?parse_url($a['provider_endpoint']):false;
        if(!is_array($url)||array_diff(array_keys($url),['scheme','host','port'])||($url['scheme']??null)!=='tcp'||($url['host']??null)!==$a['provider_address']||!isset($url['port'])||$url['port']<1||$url['port']>65535)throw new \InvalidArgumentException('Hosted observer endpoint mismatch');
        foreach(['cpu_budget_ms','max_payment_units'] as $name)if(Amount::integer($h[$name]??null)<1)throw new \InvalidArgumentException('Hosted charge/resource limit required');
        return $a;
    }
    /** Reads the actual WASM registry against candidate consensus state; never trusts an RPC proof. */
    public static function authorize(State $state,array $h,array $p,string $payer): void {
        $id=hex2bin($p['registry_contract_id']);$code=$state->getContractCode($id);
        if($code===null || bin2hex(ContractId::fromWasm($code['wasm'])->bytes)!==$p['registry_code_hash'] || ($code['abi_version']??null)!==1)throw new \InvalidArgumentException('Hosted registry code mismatch');
        $a=$h['document']['assignment'];$app=pack('N',$a['application_id']);
        $query=static function(string $method,string $input,int $length)use($state,$id):string {
            $result=ContractTransactionExecutor::query($state,$id,$method,$input,1000000);
            if(!$result->runtime->isSuccess())throw new \InvalidArgumentException('Hosted registry query failed');
            $effects=$result->overlay->effects();
            if($effects['storageWrites']||$effects['storageDeletes']||$effects['accountBalanceDeltas']||$effects['contractBalanceDeltas']||$effects['events'])throw new \InvalidArgumentException('Registry query attempted mutation');
            $bytes=$effects['returnValue'];if(strlen($bytes)!==$length)throw new \InvalidArgumentException('Hosted registry ABI mismatch');return $bytes;
        };
        $record=$query('qap',$app,80);$mask=unpack('n',$query('qau',$app.hex2bin($payer),2))[1];
        $release=$query('qre',$app.pack('N',$a['release_id']),38);$digest=$query('qrs',$app.pack('N',$a['release_id']).$a['service']."\0",32);
        if(unpack('N',substr($record,68,4))[1]!==$h['authority_epoch'] || unpack('N',substr($record,72,4))[1]!==$a['release_id']
            ||($mask&2)!==2 || unpack('N',substr($release,0,4))[1]!==$a['release_id'] || 'sha256:'.bin2hex($digest)!==$a['image_digest'])throw new \InvalidArgumentException('Hosted registry authority/release revoked');
    }
    public static function scope(array $h): string { $a=$h['document']['assignment'];return $a['registry_contract_id'].':'.$a['application_id'].':'.$a['service']; }
}
