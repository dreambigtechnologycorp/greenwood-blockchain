<?php

declare(strict_types=1);
namespace Greenwood\Tests\Unit\Edge;
use Greenwood\Edge\{ApprovedDeploymentStore,AuthenticatedRouteController,EdgeRouteRegistry};
use Greenwood\ControlPlane\ProviderObservationService;
use PHPUnit\Framework\TestCase;

final class AuthenticatedRouteControllerTest extends TestCase
{
    private string $dir;
    private bool $authorized=true;
    private array $override=[];
    private array $assignment;
    private array $nonces=[];
    protected function setUp(): void {
        $this->dir=sys_get_temp_dir().'/gw-g2-route-'.bin2hex(random_bytes(8)); mkdir($this->dir,0700);
        $this->assignment=['application_id'=>1,'service'=>'web','release_id'=>4,'provider_id'=>str_repeat('a',64),
            'provider_address'=>'10.77.0.100','ingress_port'=>20000,'instance_id'=>'app-1-instance',
            'image_digest'=>'sha256:'.str_repeat('b',64),'provider_endpoint'=>'tcp://10.77.0.100:9443','provider_tls_name'=>'provider-a.gw.test'];
        $this->saveAssignment();
    }
    private function saveAssignment(): void { file_put_contents($this->dir.'/1-web.json',json_encode($this->assignment)); chmod($this->dir.'/1-web.json',0600); }
    protected function tearDown(): void { foreach(glob($this->dir.'/*') as $p) unlink($p); rmdir($this->dir); }
    private function registry(): EdgeRouteRegistry { return new EdgeRouteRegistry($this->dir,'greenwoodblockchain.com'); }
    private function request(): array { return ['action'=>'publish','application_id'=>1,'service'=>'web','release_id'=>4]; }
    private function controller(): AuthenticatedRouteController {
        $store=new ApprovedDeploymentStore($this->dir);
        $provider=new ProviderObservationService(str_repeat('a',64),'10.77.0.100',['edge-controller'],$store,fn()=>[
            'record'=>['state'=>'RUNNING','container_id'=>'app-1-instance','image_digest'=>'sha256:'.str_repeat('b',64)],
            'runtime'=>['runtime_state'=>'RUNNING','pid'=>123],
            'network'=>['state'=>'HEALTHY','ready'=>true,'pid'=>123,'namespace_identity'=>'boot:123:start:net','ingress_port'=>20000],
        ]);
        return new AuthenticatedRouteController($this->registry(),$store,fn($p)=>$p==='deployment-controller'&&$this->authorized,
            function($a,$nonce)use($provider){$this->nonces[]=$nonce;return array_replace($provider->handle('edge-controller',[
                'application_id'=>1,'service'=>'web','release_id'=>4,'assignment_id'=>$a['assignment_id'],'nonce'=>$nonce,
            ]),$this->override);});
    }
    public function testAuthorizedRouteIsDesiredOnlyAndRestartObtainsNewObservation(): void {
        $r=$this->controller()->handle('deployment-controller',$this->request());
        self::assertSame('gw-1-web.greenwoodblockchain.com',$r['hostname']); self::assertSame('PUBLISHED',$r['desired']);
        self::assertSame('HEALTHY',$r['state']); self::assertSame('UNKNOWN',$r['proxy_state']);
        $this->controller()->reconcile(); self::assertCount(2,$this->nonces); self::assertNotSame($this->nonces[0],$this->nonces[1]);
    }
    public function testApacheConfirmationNeverClaimsPublicReadyAndFailureStaysUncertain(): void {
        $this->controller()->handle('deployment-controller',$this->request());
        $row=$this->controller()->reconcile(static fn()=>['confirmed'=>true,'generation'=>str_repeat('f',64)])[0];
        self::assertSame('PUBLISHED',$row['state']);self::assertSame('CONFIRMED',$row['proxy_state']);self::assertFalse($row['public_ready']);
        $this->authorized=false;
        $row=$this->controller()->reconcile(static fn()=>['confirmed'=>false,'status'=>'SECURITY_WITHDRAWAL_UNCONFIRMED'])[0];
        self::assertSame('WITHDRAWN',$row['desired']);self::assertSame('APPLY_UNCERTAIN',$row['state']);self::assertSame('UNKNOWN',$row['proxy_state']);
        self::assertSame('SECURITY_WITHDRAWAL_UNCONFIRMED',$row['reason']);self::assertFalse($row['public_ready']);
    }
    public function testCallerCannotSelectBackendOrForgePrincipal(): void {
        foreach (['address','port','principal','command'] as $field) {
            try {$this->controller()->handle('deployment-controller',$this->request()+[$field=>'forged']);self::fail('Caller field accepted');}
            catch(\InvalidArgumentException){self::assertNull($this->registry()->find(1,'web'));}
        }
    }
    public function testUnauthorizedApplicationAccessDoesNotCreateRoute(): void {
        $this->expectExceptionMessage('not authorized');$this->controller()->handle('other-controller',$this->request());
    }
    public function testStaleReplayConflictingIdentityAndUnhealthyEvidenceRejected(): void {
        foreach ([['observed_at'=>time()-60],['nonce'=>str_repeat('0',64)],['provider_id'=>str_repeat('c',64)],['port'=>20001],['image_digest'=>'sha256:'.str_repeat('c',64)],['instance_id'=>'other'],['network_pid'=>456],['ready'=>false]] as $override) {
            $this->override=$override;
            try {$this->controller()->handle('deployment-controller',$this->request());self::fail('Untrusted observation accepted');}
            catch(\RuntimeException){self::assertNull($this->registry()->find(1,'web'));}
        }
    }
    public function testHealthLossWithdrawsDesiredStateWithoutClaimingProxyRemoval(): void {
        $this->controller()->handle('deployment-controller',$this->request());$this->override=['ready'=>false];
        $r=$this->controller()->reconcile()[0];self::assertSame('WITHDRAWN',$r['desired']);self::assertSame('WITHDRAWAL_PENDING',$r['state']);self::assertSame('UNKNOWN',$r['proxy_state']);
    }
    public function testAuthorityLossWithdrawsIntent(): void {
        $this->controller()->handle('deployment-controller',$this->request());$this->authorized=false;
        $r=$this->controller()->reconcile()[0];self::assertSame('AUTHORIZATION_LOST',$r['reason']);self::assertSame('WITHDRAWN',$r['intent']);
    }
    public function testAssignmentChangeNeedsNewAuthorizedPublication(): void {
        $this->controller()->handle('deployment-controller',$this->request());$this->assignment['ingress_port']=20001;$this->saveAssignment();
        $r=$this->controller()->reconcile()[0];self::assertSame('WITHDRAWN',$r['desired']);self::assertSame('ASSIGNMENT_CHANGED',$r['reason']);
    }
    public function testPlannedReplacementIsExplicit(): void {
        $this->controller()->handle('deployment-controller',$this->request());$request=$this->request();$request['action']='planned-replacement';
        $r=$this->controller()->handle('deployment-controller',$request);self::assertSame('PLANNED_REPLACEMENT',$r['reason']);self::assertSame('WITHDRAWAL_PENDING',$r['state']);
    }
    public function testFailureReasonsRemainDistinctEvenWhenNoTaskExists(): void {
        foreach ([
            [['network_state'=>'CLEANUP_REQUIRED','ready'=>false,'pid'=>null,'network_pid'=>null], 'NETWORK_CLEANUP_REQUIRED'],
            [['lifecycle_state'=>'FAILED','state'=>'ABSENT','ready'=>false,'pid'=>null,'network_pid'=>null], 'REPLACEMENT_START_FAILED'],
            [['state'=>'ABSENT','ready'=>false,'pid'=>null,'network_pid'=>null], 'PROVIDER_UNHEALTHY'],
        ] as [$override,$reason]) {
            $this->override=[];$this->controller()->handle('deployment-controller',$this->request());
            $this->override=$override;$r=$this->controller()->reconcile()[0];
            self::assertSame($reason,$r['reason']);self::assertSame('WITHDRAWN',$r['desired']);self::assertSame('WITHDRAWAL_PENDING',$r['state']);
        }
    }
    public function testAdministrativePortCannotBeApprovedAsApplicationIngress(): void {
        $this->assignment['ingress_port']=9201;$this->saveAssignment();
        $this->expectExceptionMessage('Invalid approved deployment');
        $this->controller()->handle('deployment-controller',$this->request());
    }

    public function testFailedCandidateDoesNotOverwriteHealthyApprovedBinding(): void {
        $this->controller()->handle('deployment-controller',$this->request());$this->override=['port'=>20001];
        try {$this->controller()->handle('deployment-controller',$this->request());self::fail('Changed backend accepted');}catch(\RuntimeException){}
        self::assertSame(20000,$this->registry()->find(1,'web')['binding']['port']);
    }
}
