<?php
declare(strict_types=1);
namespace Greenwood\Economics;

/** Bounded reads from a configured node origin. No caller-controlled origins or redirects. */
final class HostedFundingHttpReader
{
    public function __construct(private readonly string $origin) {
        $u=parse_url($origin);if(!is_array($u)||array_diff(array_keys($u),['scheme','host','port'])||!in_array($u['scheme']??null,['http','https'],true)||empty($u['host']))throw new \InvalidArgumentException('Pinned hosted RPC origin required');
    }
    public function get(string $path): array {
        if($path!=='/v1/chain/status'&&!preg_match('~^/v1/economics/hosted/funding/[a-f0-9]{64}$~D',$path))throw new \InvalidArgumentException('Unsupported hosted read');
        $context=stream_context_create(['http'=>['method'=>'GET','timeout'=>3,'follow_location'=>0,'ignore_errors'=>true,'header'=>"Accept: application/json\r\nConnection: close\r\n"]]);
        $stream=@fopen(rtrim($this->origin,'/').$path,'rb',false,$context);if($stream===false)throw new \RuntimeException('Hosted funding node unavailable');
        try{$raw=stream_get_contents($stream,131073);$meta=stream_get_meta_data($stream);
            if($raw===false||strlen($raw)>131072||$meta['timed_out']||!preg_match('~^HTTP/\S+ 200\\b~',$meta['wrapper_data'][0]??''))throw new \RuntimeException('Hosted funding read rejected');
            $data=json_decode($raw,true,24,JSON_THROW_ON_ERROR);if(!is_array($data))throw new \RuntimeException('Hosted funding response invalid');return $data;
        }finally{fclose($stream);}
    }
    public function __invoke(string $generation): array {
        Policy::hex($generation);$before=$this->get('/v1/chain/status');$view=$this->get('/v1/economics/hosted/funding/'.$generation);$after=$this->get('/v1/chain/status');
        foreach(['chain_id','genesis_hash','finalized_height','state_root','block_hash'] as $key)if(!array_key_exists($key,$before)||($after[$key]??null)!==$before[$key])throw new \RuntimeException('Incoherent hosted funding finality');
        if(($before['syncing']??true)!==false || ($before['height']??null)!==$before['finalized_height'] || ($before['economy']['version']??null)!==1
            ||($view['chain_id']??null)!==$before['chain_id']||($view['genesis_hash']??null)!==$before['genesis_hash']||($view['finalized_height']??null)!==$before['finalized_height']
            ||($view['state_root']??null)!==$before['state_root']||($view['finalized_hash']??null)!==$before['block_hash'])throw new \RuntimeException('Hosted funding finality mismatch');
        return $view;
    }
}
