<?php
declare(strict_types=1);
namespace Greenwood\Consensus\Network;
use Greenwood\Rpc\{ApiError,HttpResponse,RateLimited};
/** Nonblocking bounded HTTP/1.1 transport; no protocol logic or recursive peer calls. */
final class Transport
{
    private $server;
    private ?string $unixPath=null;
    private array $connections=[];
    private array $peerConnections=[];
    public function __construct(string $endpoint,private readonly \Closure $handler)
    {
        if (str_starts_with($endpoint,'unix://')) {
            // Operator/local listener: Unix-domain socket, private by default.
            $path=substr($endpoint,7);
            if ($path==='' || str_contains($path,"\0") || !str_starts_with($path,'/')) throw new \InvalidArgumentException('Invalid Unix socket path');
            if (file_exists($path)) throw new \RuntimeException('Unix socket path already exists');
            $this->server=stream_socket_server('unix://'.$path,$errno,$error);
            if (!$this->server) throw new \RuntimeException($error);
            chmod($path,0660);
            $this->unixPath=$path;
        } else {
            $url=parse_url($endpoint);
            if (($url['scheme']??'')!=='http') throw new \InvalidArgumentException('Transport requires configured HTTP over TCP endpoint');
            $this->server=stream_socket_server('tcp://'.$url['host'].':'.$url['port'],$errno,$error);
            if (!$this->server) throw new \RuntimeException($error);
        }
        stream_set_blocking($this->server,false);
    }
    public function unlinkSocket(): void { if ($this->unixPath!==null && file_exists($this->unixPath)) unlink($this->unixPath); }
    public static function envelope(string $type,string $bytes): string { return json_encode(['type'=>$type,'payload'=>base64_encode($bytes)],JSON_THROW_ON_ERROR); }
    public static function unwrap(string $json,string $type): string
    {
        if (strlen($json)>1500000) throw new \InvalidArgumentException('Envelope too large');
        $data=json_decode($json,true,8,JSON_THROW_ON_ERROR);
        if (!is_array($data) || count($data)!==2 || ($data['type']??null)!==$type || !is_string($data['payload']??null)) throw new \InvalidArgumentException('Invalid envelope');
        $bytes=base64_decode($data['payload'],true);
        if ($bytes===false || base64_encode($bytes)!==$data['payload']) throw new \InvalidArgumentException('Noncanonical base64'); return $bytes;
    }
    public function request(string $endpoint,string $path,?string $body,?\Closure $callback=null): void
    {
        $u=parse_url($endpoint); if (($u['scheme']??'')!=='http') throw new \InvalidArgumentException('Unsupported endpoint');
        $request=($body===null?'GET':'POST').' '.$path." HTTP/1.1\r\nHost: ".$u['host']."\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body??'')."\r\nConnection: keep-alive\r\n\r\n".($body??'');
        $id=$this->peerConnections[$endpoint]??null;
        if ($id!==null && isset($this->connections[$id])) {
            $c=&$this->connections[$id];
            // One in-flight request per persistent TCP connection. Bound pending bytes
            // and coalesce rebroadcasts; sync retries will recover dropped work.
            if ($c['idle']) { $c['out']=$request; $c['callback']=$callback; $c['idle']=false; $c['deadline']=microtime(true)+2; $c['request']=$request; }
            elseif ($c['request']!==$request && !isset($c['queue'][hash('sha256',$request)])) {
                if (count($c['queue'])<64 && $c['queuedBytes']+strlen($request)<=4194304) {
                    $c['queue'][hash('sha256',$request)]=[$request,$callback]; $c['queuedBytes']+=strlen($request);
                }
            }
            return;
        }
        if (count($this->connections)>=128) return;
        $s=@stream_socket_client('tcp://'.$u['host'].':'.$u['port'],$n,$e,0,STREAM_CLIENT_CONNECT|STREAM_CLIENT_ASYNC_CONNECT);
        if (!$s) return; stream_set_blocking($s,false);
        $this->peerConnections[$endpoint]=(int)$s;
        $this->connections[(int)$s]=['socket'=>$s,'out'=>$request,'request'=>$request,'in'=>'','client'=>true,'callback'=>$callback,'deadline'=>microtime(true)+2,'endpoint'=>$endpoint,'idle'=>false,'queue'=>[],'queuedBytes'=>0];
    }
    public function poll(): void
    {
        while (($s=@stream_socket_accept($this->server,0))!==false) {
            if (count($this->connections)>=128) { fclose($s); break; }
            stream_set_blocking($s,false);
            $this->connections[(int)$s]=['socket'=>$s,'out'=>'','in'=>'','client'=>false,'callback'=>null,'deadline'=>microtime(true)+2,'peer'=>@stream_socket_get_name($s,true)?:''];
        }
        foreach (array_keys($this->connections) as $id) {
            $c=&$this->connections[$id]; $s=$c['socket'];
            try {
                if ($c['deadline']<microtime(true)) { $this->close($id); continue; }
                if ($c['out']!=='') {
                    $n=@fwrite($s,$c['out']); if ($n===false) { $this->close($id); continue; }
                    $c['out']=substr($c['out'],$n);
                    if ($c['out']==='' && ($c['responded']??false)) {
                        if ($c['closeAfterResponse']) { $this->close($id); continue; }
                        $c['responded']=false; $c['in']=''; $c['deadline']=microtime(true)+10;
                    }
                }
                $chunk=@fread($s,65536);
                if ($chunk===false) { $this->close($id); continue; }
                $c['in'].=$chunk;
                if (strlen($c['in'])>1516384) throw new \InvalidArgumentException('HTTP body too large');
                $end=strpos($c['in'],"\r\n\r\n");
                if ($end===false) { if (strlen($c['in'])>16384) throw new \InvalidArgumentException('HTTP headers too large'); if (feof($s)) $this->close($id); continue; }
                if ($end>16384) throw new \InvalidArgumentException('Headers too large');
                $head=substr($c['in'],0,$end); $lines=explode("\r\n",$head); $first=array_shift($lines); $headers=[];
                foreach ($lines as $line) {
                    if (!str_contains($line,':')) throw new \InvalidArgumentException('Invalid header');
                    [$k,$v]=explode(':',$line,2); $k=strtolower(trim($k));
                    if (isset($headers[$k])) throw new \InvalidArgumentException('Duplicate header'); $headers[$k]=trim($v);
                }
                $hasBody = isset($headers['content-length']);
                if (isset($headers['transfer-encoding']) || ($c['client'] && !$hasBody) || ($hasBody && !preg_match('/^(0|[1-9][0-9]{0,6})$/D',$headers['content-length']))) throw new \InvalidArgumentException('Invalid HTTP framing');
                $length=$hasBody ? (int)$headers['content-length'] : 0; if ($length>1500000) throw new \InvalidArgumentException('Body too large');
                if (strlen($c['in'])<$end+4+$length) { if (feof($s)) $this->close($id); continue; }
                if (strlen($c['in'])!==$end+4+$length) throw new \InvalidArgumentException('Pipelining is disabled');
                $body=substr($c['in'],$end+4,$length);
                if ($c['client']) {
                    $callback=$c['callback'];
                    $c['in']=''; $c['callback']=null; $c['request']=''; $c['idle']=true; $c['deadline']=microtime(true)+10;
                    if (($headers['connection']??'close')==='close') $this->close($id);
                    elseif ($c['queue']!==[]) {
                        [$next,$nextCallback]=array_shift($c['queue']); $c['queuedBytes']-=strlen($next);
                        $c['out']=$next; $c['request']=$next; $c['callback']=$nextCallback; $c['idle']=false; $c['deadline']=microtime(true)+2;
                    }
                    if ($callback && str_starts_with($first,'HTTP/1.1 200 ')) $callback(json_decode($body,true,16,JSON_THROW_ON_ERROR));
                } elseif (!($c['responded']??false)) {
                    $parts=explode(' ',$first);
                    if (count($parts)!==3 || $parts[2]!=='HTTP/1.1') throw new \InvalidArgumentException('Invalid request');
                    try { $data=($this->handler)($parts[0],$parts[1],$body,$headers,$c['peer']??''); $status=200; }
                    catch (ApiError $e) { $data=HttpResponse::error($e); $status=$e->httpStatus; }
                    catch (RateLimited $e) { $data=HttpResponse::error($e->error); $status=429; $data=new HttpResponse(429,$data->contentType,$data->body,['Retry-After'=>(string)$e->retryAfterSeconds]); }
                    catch (\InvalidArgumentException|\JsonException $e) { $data=['error'=>$e->getMessage()]; $status=400; }
                    $c['closeAfterResponse']=strtolower($headers['connection']??'close')!=='keep-alive';
                    $connection=$c['closeAfterResponse']?'close':'keep-alive';
                    if ($data instanceof HttpResponse) {
                        $extra=''; foreach ($data->headers as $k=>$v) $extra.=$k.': '.$v."\r\n";
                        $c['out']="HTTP/1.1 {$data->status} Result\r\nContent-Type: {$data->contentType}\r\nContent-Length: ".strlen($data->body)."\r\n{$extra}Connection: $connection\r\n\r\n".$data->body;
                    } else {
                        $json=json_encode($data,JSON_THROW_ON_ERROR);
                        $c['out']="HTTP/1.1 $status Result\r\nContent-Type: application/json\r\nContent-Length: ".strlen($json)."\r\nConnection: $connection\r\n\r\n".$json;
                    }
                    $c['responded']=true;
                    $c['deadline']=microtime(true)+2;
                }
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'TRANSPORT EXCEPTION [%s] %s at %s:%d',
                    get_class($e),
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));
                error_log($e->getTraceAsString());

                if (isset($this->connections[$id])) {
                    $this->close($id);
                }
            }
            unset($c);
        }
    }
    private function close(int $id): void
    {
        $endpoint=$this->connections[$id]['endpoint']??null;
        if ($endpoint!==null) unset($this->peerConnections[$endpoint]);
        fclose($this->connections[$id]['socket']); unset($this->connections[$id]);
    }
}
