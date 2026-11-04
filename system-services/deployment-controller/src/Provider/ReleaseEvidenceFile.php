<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Provider;

/** Short-lived credentials and approved content must bind the same immutable release. */
final class ReleaseEvidenceFile
{
    public function __construct(private readonly string $path) {}
    public function contentHash(array $assignment):string
    {
        $r=$this->read($assignment);
        if(!is_string($r['body_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$r['body_sha256']))throw new \RuntimeException('APPROVED_CONTENT_REQUIRED');
        return $r['body_sha256'];
    }
    public function verifyContent(array $assignment,string $body,\Closure $fetch):string
    {
        $r=$this->read($assignment);
        $this->contentHash($assignment);
        return \Greenwood\Edge\ReleaseContentVerifier::verify($body,$r,$fetch);
    }
    public function pullCredential(array $plan):string
    {
        $r=$this->read($plan);
        if(!is_int($r['expires_at']??null)||$r['expires_at']<=time()+30||!is_string($r['token']??null)
            ||strlen($r['token'])<16||strlen($r['token'])>8192||preg_match('/\s/',$r['token']))throw new \RuntimeException('CURRENT_SCOPED_CREDENTIAL_REQUIRED');
        $repository='chains/'.$plan['chain_id'].'/registries/'.$plan['registry_contract_id'].'/apps/'.$plan['application_id'].'/'.$plan['service'];
        if(($r['scope']??null)!=='repository:'.$repository.':pull')throw new \RuntimeException('PULL_SCOPE_MISMATCH');
        return $r['token'];
    }
    private function read(array $a):array
    {
        if(!str_starts_with($this->path,'/'))throw new \RuntimeException('ABSOLUTE_EVIDENCE_PATH_REQUIRED');
        for($dir=dirname($this->path);;$dir=dirname($dir)){
            if(is_link($dir)||!is_dir($dir)||fileowner($dir)!==0||(fileperms($dir)&0022)!==0)throw new \RuntimeException('PROTECTED_EVIDENCE_DIRECTORY_REQUIRED');
            if($dir==='/')break;
        }
        clearstatcache(true,$this->path);
        if(is_link($this->path)||!is_file($this->path)||fileowner($this->path)!==0||(fileperms($this->path)&0077)!==0
            ||filesize($this->path)>16384)throw new \RuntimeException('PROTECTED_RELEASE_EVIDENCE_REQUIRED');
        $r=json_decode(file_get_contents($this->path),true,16,JSON_THROW_ON_ERROR);
        foreach(['chain_id','registry_contract_id','application_id','service','release_id','image_digest']as$key)
            if(!isset($a[$key])||($r[$key]??null)!==$a[$key])throw new \RuntimeException('RELEASE_EVIDENCE_MISMATCH');
        return $r;
    }
}
