<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\Service\{ContainerService,SnapshotService,LeaseService,TaskService};
use Greenwood\Contracts\{ContainerdException,ContainerdRpcException};
use Greenwood\Crypto\ProtocolHash;
use Greenwood\Economics\CanonicalJson;

/** Capability produced only by read-only inspection of ALL intended runtime resources. */
final readonly class VacantSystemService
{
    private function __construct(public string $recordHash,public string $namespace,public string $socket,public string $snapshotter){}
    public static function inspect(ContainerdClientSet $clients,array $record): self
    {
        $id=$record['container_id'];
        foreach([static fn()=>(new ContainerService($clients))->get($id),static fn()=>(new TaskService($clients))->get($id),static fn()=>(new SnapshotService($clients))->stat('gw-system-view-'.$id)] as $inspect){
            try{$inspect();}catch(ContainerdException $e){if($e->getCode()===ContainerdRpcException::RPC_NOT_FOUND)continue;throw $e;}
            throw new \RuntimeException('Runtime resource still exists; automatic ownership adoption denied');
        }
        if((new LeaseService($clients))->exists('gw-system-lease-'.$id))throw new \RuntimeException('Unknown service lease remains');
        return new self(bin2hex(ProtocolHash::hash('GREENWOOD_SYSTEM_SERVICE_RECORD_V1',CanonicalJson::encode($record))),$clients->getConnection()->getNamespace(),$clients->getConnection()->getSocketPath(),$clients->getSnapshotter());
    }
}
