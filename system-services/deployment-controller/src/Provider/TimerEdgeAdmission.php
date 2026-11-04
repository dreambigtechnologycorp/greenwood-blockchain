<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Provider;
use Greenwood\Edge\ApprovedDeploymentStore;
use Greenwood\DeploymentController\Assignment\AssignmentDistributor;

/** Protected file admission for the existing node-4 timer. Never runs or reloads Apache. */
final class TimerEdgeAdmission {
    public function __construct(private readonly string $directory,private readonly AssignmentDistributor $distributor,
        private readonly \Closure $expectedBodyHash,private readonly bool $verifyOnly=false,
        private readonly ?string $assignmentDirectory=null) {
        foreach(['assignments','content','apply','admissions']as$name){$p=$directory.'/'.$name;if(is_link($p)||!is_dir($p)||(fileperms($p)&0077)!==0||!in_array(fileowner($p),[0,posix_geteuid()],true))throw new \RuntimeException('Protected edge directory required');}
    }
    public function admit(string $operation,array $assignment,array $authority):void {
        if($assignment['application_id']<1||($authority['authorized']??false)!==true)throw new \RuntimeException('PROTECTED_APPLICATION');
        $body=($this->expectedBodyHash)($assignment);if(!preg_match('/^[a-f0-9]{64}$/D',$body))throw new \RuntimeException('APPROVED_CONTENT_UNAVAILABLE');
        $plain=$assignment;unset($plain['assignment_id']);ksort($plain);
        if(hash('sha256',json_encode($plain,JSON_THROW_ON_ERROR))!==$assignment['assignment_id'])throw new \RuntimeException('ASSIGNMENT_ID_MISMATCH');
        $name=$assignment['application_id'].'-'.$assignment['service'].'.json';
        // This admission path supports new applications only. Existing publications
        // require an explicit withdrawal/update lifecycle, never implicit replacement.
        $content=$this->directory.'/content/'.$name;
        $expected=['application_id'=>$assignment['application_id'],'service'=>$assignment['service'],'release_id'=>$assignment['release_id'],'body_sha256'=>$body];
        if(is_link($content)||(is_file($content)&&json_decode(file_get_contents($content),true)!==$expected))throw new \RuntimeException('EXISTING_PUBLICATION_CONFLICT');
        $document=['chain_id'=>$authority['chain_id'],'registry_contract_id'=>$authority['registry_contract'],'operation_id'=>$operation,
            'account_id'=>$authority['account_id'],'version'=>1,'previous_generation'=>null,'assignment'=>$plain];
        $ack=$this->verifyOnly?$this->distributor->verify($document):$this->distributor->distribute($document);
        if(($ack['acknowledged']??false)!==true||$ack['assignment_id']!==$assignment['assignment_id'])throw new \RuntimeException('CROSS_NODE_ASSIGNMENT_UNCONFIRMED');
        $read=(new ApprovedDeploymentStore($this->assignmentDirectory??$this->directory.'/assignments',$assignment['chain_id'],$assignment['registry_contract_id']))->get($assignment['application_id'],$assignment['service'],$assignment['release_id']);
        if($read!==$assignment)throw new \RuntimeException('ASSIGNMENT_READBACK_MISMATCH');
        $this->install($this->directory.'/admissions/'.$name,['document'=>$document,'body_sha256'=>$body]);
        $this->install($this->directory.'/content/'.$name,['application_id'=>$assignment['application_id'],'service'=>$assignment['service'],'release_id'=>$assignment['release_id'],'body_sha256'=>$body]);
    }
    public function inspect(string $operation,array $assignment,array $authority):array {
        $plain=$assignment;unset($plain['assignment_id']);
        $this->distributor->verify(['chain_id'=>$authority['chain_id'],'registry_contract_id'=>$authority['registry_contract'],'operation_id'=>$operation,
            'account_id'=>$authority['account_id'],'version'=>1,'previous_generation'=>null,'assignment'=>$plain]);
        $path=$this->directory.'/apply/apply.json';
        if(is_link($path)||!is_file($path)||(fileperms($path)&0022)!==0)return ['confirmed'=>false];
        $a=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);$effective=$a['effective']??[];
        if(($a['confirmed']??false)!==true||($a['status']??null)!=='CONFIRMED'||($a['generation']??null)!==($effective['generation']??null))return ['confirmed'=>false];
        foreach($effective['routes']??[]as$route){
            if(($route['application_id']??null)!==$assignment['application_id']||($route['service']??null)!==$assignment['service'])continue;
            // The physical timer's route identity hashes the store output including assignment_id.
            if(($route['assignment_id']??null)!==hash('sha256',json_encode($assignment,JSON_THROW_ON_ERROR))||($route['release_id']??null)!==$assignment['release_id']
                ||($route['binding']['address']??null)!==$assignment['provider_address']||($route['binding']['port']??null)!==$assignment['ingress_port']
                ||($route['binding']['image_digest']??null)!==$assignment['image_digest']||($route['desired']??null)!=='PUBLISHED'||($route['state']??null)!=='HEALTHY')return ['confirmed'=>false];
            return ['confirmed'=>true,'generation'=>$a['generation'],'body_sha256'=>($this->expectedBodyHash)($assignment)];
        }return ['confirmed'=>false];
    }
    private function install(string $path,array $value):void {
        if(is_link($path))throw new \RuntimeException('UNSAFE_ADMISSION_PATH');
        if(is_file($path)){if(json_decode(file_get_contents($path),true)!==$value)throw new \RuntimeException('ASSIGNMENT_CONFLICT');return;}
        $temp=tempnam(dirname($path),'.admission-');$f=fopen($temp,'wb');
        try{chmod($temp,0600);$data=json_encode($value,JSON_THROW_ON_ERROR);if(fwrite($f,$data)!==strlen($data)||!fflush($f)||!fsync($f))throw new \RuntimeException('ADMISSION_WRITE_FAILED');}
        finally{fclose($f);}
        // link is atomic and refuses to replace another operation's publication.
        try{if(!link($temp,$path))throw new \RuntimeException('ADMISSION_CONFLICT');$dir=fopen(dirname($path),'r');try{if(!fsync($dir))throw new \RuntimeException('ADMISSION_SYNC_FAILED');}finally{fclose($dir);}}
        finally{unlink($temp);}
    }
}
