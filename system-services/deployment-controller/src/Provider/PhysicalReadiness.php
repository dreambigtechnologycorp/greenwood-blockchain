<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Provider;
use Greenwood\ControlPlane\DeploymentReadinessService;
use Greenwood\DeploymentController\Assignment\AssignmentDocument;
use Greenwood\Edge\ApprovedDeploymentStore;

/** Node-local evidence and fresh probes; never installs or reloads an edge route. */
final class PhysicalReadiness
{
    public function __construct(private readonly array $config,private readonly TimerEdgeAdmission $admission,
        private readonly TimerAssignmentAuthority $admissionAuthority,private readonly \Closure $authorize,
        private readonly ProviderObserverClient $observer) {}

    public function inspect(array $request):array
    {
        $url=fn(array $a):string=>$this->config['public_urls'][$a['application_id'].'-'.$a['service']]
            ??'https://gw-'.$a['application_id'].'-'.$a['service'].'.greenwoodblockchain.com/';
        $service=new DeploymentReadinessService($this->snapshot(...),$this->authority(...),$this->observer->verifyApplication(...),
            function(array $s,string $url):array{
                [$status,$body]=$this->get('http://127.0.0.1:8081/',['Host: greenwood-unpublished.invalid']);
                if($status!==403||!str_contains($body,'greenwood-generation-'.$s['marker']))throw new \RuntimeException('EFFECTIVE_GENERATION_UNVERIFIED');
                $origin=$this->config['local_origin'];
                if(!in_array($origin,['http://127.0.0.1:80','http://127.0.0.1:8081'],true))throw new \RuntimeException('LOCAL_EDGE_ORIGIN_REQUIRED');
                [$status,$body]=$this->get($origin.'/', ['Host: '.parse_url($url,PHP_URL_HOST)]);
                if($status!==200||$this->content($s['assignment'],$body,$origin,['Host: '.parse_url($url,PHP_URL_HOST)])!==$s['body_sha256'])throw new \RuntimeException('LOCAL_CONTENT_UNVERIFIED');
                [$status,$body]=$this->get($url.'?readiness='.bin2hex(random_bytes(16)),['Cache-Control: no-cache']);
                if($status!==200||$this->content($s['assignment'],$body,rtrim($url,'/'),['Cache-Control: no-cache'])!==$s['body_sha256'])throw new \RuntimeException('PUBLIC_CONTENT_UNVERIFIED');
                return ['verified'=>true,'status'=>200,'url'=>$url,'body_sha256'=>$s['body_sha256']];
            },static fn():int=>time(),$url,1);
        return $service->inspect($request);
    }
    private function record(array $a):array
    {
        return $this->read($this->config['admissions_directory'].'/'.$a['application_id'].'-'.$a['service'].'.json');
    }
    private function content(array $a,string $body,string $origin,array $headers):string
    {
        $path=$this->config['content_evidence_files'][$a['application_id'].'-'.$a['service']]
            ??$this->config['content_evidence_file']??'';
        return (new ReleaseEvidenceFile($path))->verifyContent(
            $a,$body,fn(string $path):array=>$this->get($origin.$path,$headers));
    }
    private function authority(array $a):array
    {
        $d=AssignmentDocument::validate($this->record($a)['document']);
        if(AssignmentDocument::assignmentId($d)!==$a['assignment_id'])throw new \RuntimeException('ADMISSION_IDENTITY_CHANGED');
        return ($this->authorize)($d['account_id'],['chain_id'=>$d['chain_id'],'registry_contract_id'=>$d['registry_contract_id'],
            'application_id'=>$a['application_id'],'service'=>$a['service'],'release_number'=>$a['release_id'],'image_digest'=>$a['image_digest']]);
    }
    private function snapshot(array $r):array
    {
        $record=$this->record($r);$d=AssignmentDocument::validate($record['document']);
        $a=(new ApprovedDeploymentStore($this->config['assignments_directory'],$d['chain_id'],$d['registry_contract_id']))->get($r['application_id'],$r['service'],$r['release_id']);
        $content=$this->read($this->config['edge_directory'].'/content/'.$r['application_id'].'-'.$r['service'].'.json');
        if(!$this->admissionAuthority->authorize($a,$content))throw new \RuntimeException('ADMISSION_UNVERIFIED');
        $route=$this->admission->inspect($d['operation_id'],$a,$this->authority($a));
        if(($route['confirmed']??false)!==true||($route['body_sha256']??null)!==$record['body_sha256'])throw new \RuntimeException('EDGE_UNCONFIRMED');
        $apply=$this->read($this->config['edge_directory'].'/apply/apply.json');
        $installed=$this->readBytes($this->config['managed_configuration']);
        if(($apply['generation']??null)!==$route['generation']||($apply['effective']['configuration']??null)!==$installed)throw new \RuntimeException('INSTALLED_EDGE_MISMATCH');
        $marker=$apply['effective']['marker']??null;
        if(!is_string($marker)||!preg_match('/^[a-f0-9]{64}$/D',$marker))throw new \RuntimeException('EDGE_MARKER_REQUIRED');
        return ['assignment'=>$a,'generation'=>$route['generation'],'body_sha256'=>$record['body_sha256'],
            'configuration_sha256'=>hash('sha256',$installed),'marker'=>$marker];
    }
    private function read(string $path):array {return json_decode($this->readBytes($path),true,32,JSON_THROW_ON_ERROR);}
    private function readBytes(string $path):string
    {
        if(is_link($path)||!is_file($path)||fileowner($path)!==0||(fileperms($path)&0022)!==0||filesize($path)>1048576)throw new \RuntimeException('PROTECTED_EDGE_EVIDENCE_REQUIRED');
        return file_get_contents($path);
    }
    private function get(string $url,array $headers):array
    {
        $body='';$curl=curl_init($url);
        try{
            curl_setopt_array($curl,[CURLOPT_PROXY=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>5,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_WRITEFUNCTION=>static function($c,string $part)use(&$body):int{if(strlen($body)+strlen($part)>1048576)return 0;$body.=$part;return strlen($part);}]);
            if(curl_exec($curl)===false)throw new \RuntimeException('EDGE_PROBE_UNAVAILABLE');
            return [curl_getinfo($curl,CURLINFO_RESPONSE_CODE),$body];
        }finally{curl_close($curl);}
    }
}
