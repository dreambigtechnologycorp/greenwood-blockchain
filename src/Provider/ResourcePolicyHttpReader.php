<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Network\ResourcePolicyState;
use Greenwood\Economics\Policy;

/** Reads an administrator-pinned node, never arbitrary discovery peers. No proof-of-independence claim. */
final class ResourcePolicyHttpReader
{
    public function __construct(private readonly string $origin,private readonly string $chain,private readonly string $genesis,private readonly ?string $tlsFingerprint=null,private readonly ?\Closure $fetch=null)
    {
        Policy::hex($genesis);$u=parse_url($origin);
        if(!is_array($u)||array_diff(array_keys($u),['scheme','host','port'])||empty($u['host'])||!(($u['scheme']??null)==='https'||($u['scheme']??null)==='http'&&in_array($u['host'],['127.0.0.1','[::1]'],true)))throw new \InvalidArgumentException('Pinned TLS or local node origin required');
        if($u['scheme']==='https')Policy::hex($tlsFingerprint);
    }
    private function get(string $path): array
    {
        if($this->fetch)return ($this->fetch)($path);
        $ssl=['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false];if($this->tlsFingerprint!==null)$ssl['peer_fingerprint']=['sha256'=>$this->tlsFingerprint];
        $context=stream_context_create(['http'=>['timeout'=>3,'follow_location'=>0,'ignore_errors'=>true,'header'=>"Accept: application/json\r\nConnection: close\r\n"],'ssl'=>$ssl]);
        $stream=@fopen($this->origin.$path,'rb',false,$context);if(!$stream)throw new \RuntimeException('Pinned policy node unavailable');
        try{$raw=stream_get_contents($stream,1048577);$meta=stream_get_meta_data($stream);if($raw===false||strlen($raw)>1048576||$meta['timed_out']||!preg_match('~^HTTP/\S+ 200\b~',$meta['wrapper_data'][0]??''))throw new \RuntimeException('Policy node response rejected');$r=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($r))throw new \RuntimeException('Policy response object required');return $r;}
        finally{fclose($stream);}
    }
    public function read(): ResourcePolicyView
    {
        $head=$this->get('/v1/chain/status');$height=$head['finalized_height']??null;
        if(($head['chain_id']??null)!==$this->chain||($head['genesis_hash']??null)!==$this->genesis||!is_int($height)||$height<1||($head['height']??null)!==$height||($head['syncing']??true)!==false)throw new \RuntimeException('Wrong or unsynchronized policy chain');
        Policy::hex($head['state_root']??null);Policy::hex($head['block_hash']??null);$records=[];
        foreach(['operator'=>'operators','service'=>'system-services'] as $kind=>$route){$cursor='';$count=0;
            for($page=0;$page<64;$page++){
                $view=$this->get('/v1/resources/'.$route.'?limit=16'.($cursor===''?'':'&cursor='.$cursor));
                foreach(['chain_id'=>$this->chain,'genesis_hash'=>$this->genesis,'finalized_height'=>$height,'state_root'=>$head['state_root'],'finalized_hash'=>$head['block_hash']] as $key=>$value)if(($view[$key]??null)!==$value)throw new \RuntimeException('Policy pages are not one coherent finalized view');
                if(!is_array($view['records']??null)||!array_is_list($view['records'])||count($view['records'])>16)throw new \RuntimeException('Bounded policy page required');
                foreach($view['records'] as $row){$r=ResourcePolicyState::validate($row['record']??[]);$id=Policy::hex($row['id']??null);
                    if($id!==$r['id']||strcmp($id,$cursor)<=0||$r['kind']!==$kind||($row['policy_hash']??null)!==ResourcePolicyState::hash($r)||$r['status']!=='active'||$r['activation_height']>$height||$r['expires_height']<=$height||!is_int($r['approved_height']??null)||$r['approved_height']<1||$r['approved_height']>$height||isset($records[$kind][$id])||++$count>1000)throw new \RuntimeException('Invalid/replayed effective policy record');
                    $records[$kind][$id]=[$r];$cursor=$id;
                }
                if(!array_key_exists('next_cursor',$view))throw new \RuntimeException('Policy cursor missing');$next=$view['next_cursor'];if($next===null)break;
                if(!$view['records']||$next!==$cursor||$page===63)throw new \RuntimeException('Nonprogressing or excessive policy pagination');
            }
        }
        $after=$this->get('/v1/chain/status');foreach(['chain_id','genesis_hash','finalized_height','state_root','block_hash','height','syncing'] as $key)if(($after[$key]??null)!==$head[$key])throw new \RuntimeException('Policy head changed during read');
        return new ResourcePolicyView($this->chain,$this->genesis,$height,$head['state_root'],$head['block_hash'],ResourcePolicyState::restore($records),'PINNED_NODE_ASSERTION');
    }
}
