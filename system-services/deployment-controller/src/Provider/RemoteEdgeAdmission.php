<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Provider;
use Greenwood\ControlPlane\MutualTlsChannel;

/** Node 4 owns admission files and readiness. No remote shell or shared SQLite. */
final class RemoteEdgeAdmission
{
    public function __construct(private readonly MutualTlsChannel $channel,private readonly array $peer) {}
    public function admit(string $operation,array $assignment,array $authority):void
    {
        $reply=$this->call('admit',['operation_id'=>$operation,'assignment'=>$assignment,'account_id'=>$authority['account_id']]);
        if(($reply['admitted']??false)!==true||($reply['assignment_id']??null)!==$assignment['assignment_id'])throw new \RuntimeException('EDGE_ADMISSION_UNCONFIRMED');
    }
    public function inspect(string $operation,array $assignment,array $authority):array
    {
        return $this->call('inspect',['operation_id'=>$operation,'assignment'=>$assignment,'account_id'=>$authority['account_id']]);
    }
    public function readiness(array $request):array {return $this->call('readiness',['request'=>$request]);}
    private function call(string $action,array $body):array
    {
        return $this->channel->request($this->peer['endpoint'],$this->peer['tls_name'],$this->peer['identity'],['action'=>$action]+$body,30);
    }
}
