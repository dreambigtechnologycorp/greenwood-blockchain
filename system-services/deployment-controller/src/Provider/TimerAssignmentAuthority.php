<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Provider;
use Greenwood\DeploymentController\Assignment\{AssignmentDocument,AssignmentDistributor};

/** New-app authorization hook for the existing timer, not a network edge API. */
final class TimerAssignmentAuthority {
    public function __construct(private readonly string $directory,private readonly AssignmentDistributor $distributor,
        private readonly \Closure $authorize) {
        if(is_link($directory)||!is_dir($directory)||(fileperms($directory)&0077)!==0
            ||!in_array(fileowner($directory),[0,posix_geteuid()],true))throw new \RuntimeException('PROTECTED_ADMISSIONS_REQUIRED');
    }
    public function authorize(array $assignment,array $content):bool {
        if(($assignment['application_id']??0)<1)return false;
        // Validate filename components before touching the filesystem.
        $plain=$assignment;unset($plain['assignment_id']);
        $id=$assignment['application_id'];$service=$assignment['service'];
        if(!is_int($id)||!is_string($service)||!preg_match('/^[a-z][a-z0-9-]{0,62}$/D',$service))return false;
        $path=$this->directory.'/'.$id.'-'.$service.'.json';clearstatcache(true,$path);
        if(is_link($path)||!is_file($path)||(fileperms($path)&0077)!==0||filesize($path)>16384
            ||!in_array(fileowner($path),[0,posix_geteuid()],true))return false;
        $record=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        if(array_keys($record)!==['document','body_sha256']||!is_string($record['body_sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$record['body_sha256']))return false;
        $d=AssignmentDocument::validate($record['document']);
        if($d['version']!==1||AssignmentDocument::canonical($d['assignment'])!==AssignmentDocument::canonical($plain)
            ||AssignmentDocument::assignmentId($d)!==($assignment['assignment_id']??null))return false;
        $expected=['application_id'=>$id,'service'=>$service,'release_id'=>$assignment['release_id'],'body_sha256'=>$record['body_sha256']];
        if(AssignmentDocument::canonical($content)!==AssignmentDocument::canonical($expected))return false;
        // Independent finalized authority check uses the original authenticated
        // account retained in the canonical document, never a fixed operator account.
        $proof=($this->authorize)($d['account_id'],['chain_id'=>$d['chain_id'],'registry_contract_id'=>$d['registry_contract_id'],'application_id'=>$id,'service'=>$service,
            'release_number'=>$assignment['release_id'],'image_digest'=>$assignment['image_digest']]);
        if(($proof['registry_contract']??null)!==$d['registry_contract_id']||($proof['authorized']??false)!==true||($proof['account_id']??null)!==$d['account_id']
            ||($proof['chain_id']??null)!==$d['chain_id']||($proof['release_id']??null)!==$assignment['release_id']
            ||!is_int($proof['observed_at']??null)||$proof['observed_at']<time()-15||$proof['observed_at']>time()+2)return false;
        foreach(['application_id','service','image_digest']as$k)if(($proof[$k]??null)!==$assignment[$k])return false;
        return ($this->distributor->verify($d)['acknowledged']??false)===true;
    }
}
