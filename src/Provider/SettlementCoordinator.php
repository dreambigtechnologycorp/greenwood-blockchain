<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Node\Chain;
use Greenwood\Transaction\Envelope;

/** Bounded permissionless relay of already signed native actions. No keys or new signatures. */
final class SettlementCoordinator
{
    public function __construct(private readonly SettlementOutbox $outbox,private readonly \Closure $broadcast){}
    public static function forHttp(SettlementOutbox $outbox,string $endpoint,string $chain): self
    {
        $url=parse_url($endpoint);
        if(!is_array($url)||array_diff(array_keys($url),['scheme','host','port'])||empty($url['host'])||!(($url['scheme']??null)==='https'||($url['scheme']??null)==='http'&&in_array($url['host'],['127.0.0.1','[::1]'],true)))throw new \InvalidArgumentException('TLS or local-only pinned relay origin required');
        $client=new HttpChainClient($endpoint,$chain);return new self($outbox,static fn(string $bytes):string=>$client->submit(Envelope::decode($bytes)));
    }
    public function tick(Chain $verifiedChain,int $now,int $limit=16): array
    {
        $results=[];foreach($this->outbox->due($now,$limit) as $id){
            $r=$this->outbox->reconcile($id,$verifiedChain,256);
            if(in_array($r['state'],['FINALIZED','NONCE_CONFLICT'],true)){$results[]=$r;continue;}
            if((int)$r['cursor']<$verifiedChain->tip->getHeight()){$results[]=$r+['relay'=>'AWAITING_BOUNDED_HISTORY_SCAN'];continue;}
            try{$this->outbox->submit($id,$now,$this->broadcast);$results[]=$this->outbox->status($id)+['relay'=>'SUBMITTED_UNCERTAIN'];}
            catch(\Throwable){$results[]=$this->outbox->status($id)+['relay'=>'RETRY_OR_RECONCILIATION_REQUIRED'];}
        }return $results;
    }
}
