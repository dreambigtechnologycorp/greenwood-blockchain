<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Economics\{HostedFundingGuard,HostedFundingHttpReader};

/** Composition from protected server configuration; no request can choose a wallet, node or journal. */
final class HostedRuntimeFunding
{
    private function __construct(private readonly ?HostedFundingGuard $guard,private readonly ?HostedAssignmentJournal $journal,private readonly ?string $meterDirectory=null,private readonly int $sampleMs=15000,private readonly ?string $meterSocket=null,private readonly bool $meterNetwork=false) {}
    public static function fromFile(?string $path,ProviderConfig $config,string $providerPublicKey): self {
        if($path===null)return new self(null,null);
        for($part=$path;;$part=dirname($part)){
            if(!str_starts_with($path,'/')||is_link($part)||!file_exists($part)||fileowner($part)!==0||(fileperms($part)&0022)!==0)throw new \RuntimeException('Root-owned hosted funding configuration required');
            if($part==='/')break;
        }
        if(!is_file($path)||(fileperms($path)&0077)!==0||filesize($path)>16384)throw new \RuntimeException('Private hosted funding configuration required');
        $c=json_decode(file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
        if(($c['chain_id']??null)!==$config->chainId || ($c['provider_public_key']??null)!==bin2hex($providerPublicKey))throw new \RuntimeException('Hosted funding identity mismatch');
        $reader=new HostedFundingHttpReader(rtrim($config->rpcEndpoint,'/'));
        $stale=$c['max_stale_head_seconds']??180;if(!is_int($stale))throw new \RuntimeException('Invalid financial head watchdog deadline');
        $enabled=$c['metering_enabled']??false;$sampleMs=$c['metering_sample_ms']??15000;
        if(!is_bool($enabled)||!is_int($sampleMs)||$sampleMs<1000||$sampleMs>60000)throw new \RuntimeException('Invalid explicit local metering policy');
        $transport=$c['metering_transport']??'system_service_unix';$socket=$c['metering_socket']??null;
        if(!in_array($transport,['system_service_unix','local_diagnostic'],true))throw new \RuntimeException('Unknown metering transport');
        $network=$c['metering_network_enabled']??false;if(!is_bool($network))throw new \RuntimeException('Explicit network collector consent required');
        if($enabled&&$transport==='system_service_unix'&&(!is_string($socket)||!str_starts_with($socket,'/var/lib/greenwood/system-service-data/')||basename($socket)!=='meter.sock'))throw new \RuntimeException('Reviewed private system-service meter socket required');
        return new self(new HostedFundingGuard($reader->__invoke(...),$config->chainId,$c['genesis_hash']??'',bin2hex($providerPublicKey),new FinalizedHeadWatchdog($stale)),new HostedAssignmentJournal($c['receiver_ledger']??''),$enabled&&$transport==='local_diagnostic'?$config->dataPath.'/resource-metering':null,$sampleMs,$enabled&&$transport==='system_service_unix'?$socket:null,$network);
    }
    public function require(int $app,string $service,string $imageRef): array {
        if($this->guard===null||$this->journal===null)throw new \RuntimeException('HOSTED_SERVICE_SETTLEMENT_NOT_QUALIFIED');
        return $this->guard->require($this->journal->document($app,$service,$imageRef));
    }
    public function meteringMonitor(ProviderApplicationRegistry $applications,?ProviderApplicationNetwork $network=null): ?HostedMeteringMonitor {
        $journal=$this->meterSocket!==null?new MeteringSocketClient($this->meterSocket):($this->meterDirectory!==null?new MeteringService($this->meterDirectory):null);
        return $journal===null?null:HostedMeteringMonitor::forProvider($journal,$applications,$this->require(...),$this->sampleMs,$this->meterNetwork?$network:null);
    }
}
