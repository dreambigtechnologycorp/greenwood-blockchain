<?php
declare(strict_types=1);
namespace Greenwood\Compute\Containerd;

use Greenwood\Network\ResourcePolicyState;
use Greenwood\Economics\{CanonicalJson,Policy};

/** Adapter from locally verified finalized native policy to existing containerd primitives.
 * Does not fetch RPC, install privileges, or start a task. Operator approval is mandatory.
 */
final class GovernedServiceDefinition
{
    public static function build(ResourcePolicyState $policies,int $height,string $policyId,array $consent): SystemServiceDefinition
    {
        Policy::hex($policyId);
        $r=$policies->current('service',$height)[$policyId]??null;
        if(!$r||($consent['opt_in']??null)!==true||($consent['policy_hash']??null)!==ResourcePolicyState::hash($r))throw new \InvalidArgumentException('Current exact manifest and local opt-in required');
        $operator=$policies->current('operator',$height)[$consent['operator_id']??'']??null;
        if(!$operator||!in_array($r['approved_role'],$operator['roles'],true))throw new \InvalidArgumentException('Operator lacks approved service role');
        if(($consent['image_digest']??null)!==$r['image_digest']||($consent['config_schema_hash']??null)!==$r['config_schema_hash'])throw new \InvalidArgumentException('Local image/config review mismatch');
        $mounts=[];
        if($r['host_permissions']===['private_journal_rw']){
            $source=$consent['journal_directory']??'';$base='/var/lib/greenwood/system-service-data/';
            if(!is_string($source)||!str_starts_with($source,$base)||realpath($source)!==$source||!is_dir($source)||is_link($source)||fileowner($source)!==65532||(fileperms($source)&0077)!==0)throw new \InvalidArgumentException('Explicit private service journal mount approval required');
            for($parent=dirname($source);;$parent=dirname($parent)){if(is_link($parent)||fileowner($parent)!==0||(fileperms($parent)&0022)!==0)throw new \InvalidArgumentException('Protected service data ancestry required');if($parent==='/')break;}
            $destination=match($r['service_id']){'greenwood-metering'=>'/var/lib/greenwood/metering','greenwood-attestor'=>'/var/lib/greenwood/attestor','greenwood-metering-coordinator'=>'/var/lib/greenwood/coordinator'};
            $mounts[]=['destination'=>$destination,'source'=>$source,'type'=>'bind','options'=>['rbind','rw','nosuid','nodev','noexec']];
        }elseif(isset($consent['journal_directory']))throw new \InvalidArgumentException('Manifest does not authorize a journal mount');
        // No containerd socket, host cgroup tree, host network or root. Collector access is separate.
        $id='gw-svc-'.substr(hash('sha256',$policyId),0,32).'-r'.$r['revision'];
        $profile=$r['execution_profile']??throw new \InvalidArgumentException('Explicit governed CPU/memory/PID resource bounds required');
        return new SystemServiceDefinition($id,$r['artifact_source'],$r['command'],[],$mounts,$r['user'],$r['host_network'],'/',$profile['cpu_millicores'],$profile['memory_bytes'],$profile['pids'],true);
    }
}
