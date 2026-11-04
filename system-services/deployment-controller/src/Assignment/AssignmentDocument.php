<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Assignment;
use Greenwood\DeploymentController\Auth\DeploymentIdentity;
final class AssignmentDocument {
    public static function canonical(array $value):string {
        $sort=static function(array $v)use(&$sort):array{foreach($v as&$x)if(is_array($x))$x=$sort($x);unset($x);if(!array_is_list($v))ksort($v);return $v;};
        return json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
    public static function validate(array $d):array {
        $keys=['chain_id','registry_contract_id','operation_id','account_id','version','previous_generation','assignment'];
        if(array_diff(array_keys($d),$keys)||array_diff($keys,array_keys($d))||$d['chain_id']!=='greenwood-physical-v1'
            ||!is_string($d['operation_id'])||!preg_match('/^[a-f0-9]{32}$/D',$d['operation_id'])||!is_string($d['account_id'])||!preg_match('/^[a-f0-9]{64}$/D',$d['account_id'])
            ||!is_int($d['version'])||$d['version']<1||$d['version']>0xffffffff
            ||($d['version']===1?$d['previous_generation']!==null:!is_string($d['previous_generation'])||!preg_match('/^[a-f0-9]{64}$/D',$d['previous_generation'])))throw new \InvalidArgumentException('INVALID_ASSIGNMENT_DOCUMENT');
        $a=$d['assignment'];DeploymentIdentity::validate($a);
        if($d['registry_contract_id']!==$a['registry_contract_id']||$d['chain_id']!==$a['chain_id'])throw new \InvalidArgumentException('ASSIGNMENT_SCOPE_MISMATCH');
        $keys=['chain_id','registry_contract_id','application_id','service','release_id','provider_id','provider_address','ingress_port','instance_id','image_digest','provider_endpoint','provider_tls_name'];
        if(!is_array($a)||array_diff(array_keys($a),$keys)||array_diff($keys,array_keys($a))||!is_int($a['application_id'])||$a['application_id']<1||$a['application_id']>0xffffffff
            ||!is_int($a['release_id'])||$a['release_id']<1||$a['release_id']>0xffffffff||!is_string($a['service'])||!preg_match('/^[a-z][a-z0-9-]{0,62}$/D',$a['service'])
            ||!is_string($a['provider_id'])||!preg_match('/^[a-f0-9]{64}$/D',$a['provider_id'])||!is_string($a['image_digest'])||!preg_match('/^sha256:[a-f0-9]{64}$/D',$a['image_digest'])
            ||!is_string($a['instance_id'])||!preg_match('/^[a-z0-9-]{1,100}$/D',$a['instance_id'])||!is_int($a['ingress_port'])||$a['ingress_port']<10000||$a['ingress_port']>65535
            ||!is_string($a['provider_address'])||!filter_var($a['provider_address'],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)||!preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/',$a['provider_address'])
            ||!is_string($a['provider_tls_name'])||!preg_match('/^[a-zA-Z0-9.-]{1,253}$/D',$a['provider_tls_name']))throw new \InvalidArgumentException('INVALID_ASSIGNMENT');
        $url=is_string($a['provider_endpoint'])?parse_url($a['provider_endpoint']):false;
        if(!is_array($url)||array_diff(array_keys($url),['scheme','host','port'])||($url['scheme']??null)!=='tcp'||($url['host']??null)!==$a['provider_address']||!isset($url['port'])||$url['port']<1||$url['port']>65535)throw new \InvalidArgumentException('INVALID_OBSERVER_ENDPOINT');
        return $d;
    }
    public static function generation(array $d):string{return hash('sha256',self::canonical(self::validate($d)));}
    public static function assignmentId(array $d):string{$a=self::validate($d)['assignment'];ksort($a);return hash('sha256',json_encode($a,JSON_THROW_ON_ERROR));}
}
