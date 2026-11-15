<?php
declare(strict_types=1);
namespace Greenwood\Resources\Metering;
use Greenwood\Provider\ProviderApplicationLinuxNetwork;
use Greenwood\Economics\Amount;

/** Host-side counters of an existing owned veth. Not Internet-only traffic or independent evidence. */
final class NetworkAccounting
{
    public static function observe(array $binding,array $runtime): array
    {
        $app=$runtime['application_id']??null;$service=$runtime['service']??null;
        $app=Amount::integer(is_int($app)?(string)$app:$app);if($app<1||$app>0xffffffff||!is_string($service)||($runtime['state']??null)!=='RUNNING'||($binding['state']??null)!=='HEALTHY'||($binding['ready']??null)!==true
            ||(int)($binding['application_id']??0)!==(int)$app||($binding['service']??null)!==$service||($binding['image_digest']??null)!==($runtime['image_digest']??null)
            ||(int)($binding['pid']??0)!==(int)($runtime['pid']??-1))throw new \RuntimeException('Healthy exact workload network binding required');
        $name='gwa'.substr(hash('sha256',(int)$app."\0".$service),0,10);
        if(($binding['host_veth']??null)!==$name)throw new \RuntimeException('Foreign network interface denied');
        $pid=(int)$runtime['pid'];$identity=ProviderApplicationLinuxNetwork::namespaceIdentity($pid);
        if(($binding['namespace_identity']??null)!==$identity)throw new \RuntimeException('Network namespace incarnation changed');
        $path='/sys/class/net/'.$name;$read=static function(string $file)use($path):string{$raw=@file_get_contents($path.'/'.$file,false,null,0,257);if($raw===false||strlen($raw)>256)throw new \RuntimeException('Owned network counter unavailable');return trim($raw);};
        $index=$read('ifindex');$peer=$read('iflink');
        if($read('ifalias')!=='greenwood-'.$name)throw new \RuntimeException('Network device ownership mismatch');
        // At the host end, RX came FROM the workload, and TX goes TO the workload.
        $egress=(string)Amount::integer($read('statistics/rx_bytes'));$ingress=(string)Amount::integer($read('statistics/tx_bytes'));
        if($read('ifindex')!==$index||$read('iflink')!==$peer||ProviderApplicationLinuxNetwork::namespaceIdentity($pid)!==$identity)throw new \RuntimeException('Network identity changed while sampling');
        return ['version'=>1,'verification_level'=>'C','source'=>'owned_host_veth_v1','network_identity'=>hash('sha256',$identity.':'.$name.':'.$index.':'.$peer),'ingress_bytes'=>$ingress,'egress_bytes'=>$egress,'internet_only'=>false,'payable'=>false];
    }
    public static function delta(array $first,array $last): array
    {
        foreach([$first,$last] as $s)if(($s['version']??null)!==1||($s['verification_level']??null)!=='C'||($s['source']??null)!=='owned_host_veth_v1'||($s['payable']??null)!==false)throw new \InvalidArgumentException('Unqualified network source required');
        \Greenwood\Economics\Policy::hex($first['network_identity']??null);
        if(($first['network_identity']??null)!==($last['network_identity']??null))throw new \InvalidArgumentException('Interface reset/replacement requires reconciliation');
        $result=[];foreach(['ingress_bytes','egress_bytes'] as $key){$start=Amount::integer($first[$key]??null);$end=Amount::integer($last[$key]??null);if($end<$start)throw new \InvalidArgumentException('Network counter reset/wrap');$result[$key]=(string)($end-$start);}return $result;
    }
}
