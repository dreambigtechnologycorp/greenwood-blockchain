<?php
declare(strict_types=1);
namespace Greenwood\SystemServices\RegistryAuth;
use Greenwood\Economics\{CanonicalJson,Policy};
use Greenwood\Crypto\ProtocolHash;

/** Explicit network-system-service publisher authority, separate from app permissions. */
final class SystemServicePublisher
{
    public function __construct(private readonly \PDO $db,private readonly string $issuerKeypair,private readonly array $policy,private readonly string $chain,private readonly string $genesis)
    {
        Policy::hex($genesis);Policy::hex($policy['authorization_hash']??null);
        if(($policy['version']??null)!==1||($policy['enabled']??null)!==true||($policy['chain_id']??null)!==$chain||($policy['genesis_hash']??null)!==$genesis||$policy['authorization_hash']===str_repeat('0',64)||!is_int($policy['expires_at']??null)||!is_array($policy['publishers']??null)||count($policy['publishers'])<1||count($policy['publishers'])>32)throw new \InvalidArgumentException('Explicit network publisher policy required');
        if(strlen($issuerKeypair)!==SODIUM_CRYPTO_SIGN_KEYPAIRBYTES)throw new \InvalidArgumentException('Provisioned issuer keypair required');
        foreach($policy['publishers'] as $key=>$services){Policy::hex($key);if(!is_array($services)||!array_is_list($services)||!$services||count($services)>6||count(array_unique($services))!==count($services))throw new \InvalidArgumentException('Exact publisher service grants required');foreach($services as $service)if(!in_array($service,['runtime','registry','registry-auth','metering','attestor','metering-coordinator'],true))throw new \InvalidArgumentException('Unknown network service grant');}
        $db->exec('CREATE TABLE IF NOT EXISTS system_publisher_clock(id INTEGER PRIMARY KEY CHECK(id=1),last_time INTEGER NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS system_publisher_nonces(key TEXT NOT NULL,nonce TEXT NOT NULL,expires INTEGER NOT NULL,PRIMARY KEY(key,nonce))');
    }
    public static function loadPolicy(string $path): array
    {
        if(realpath($path)!==$path||!is_file($path)||!in_array(fileowner($path),[0,posix_geteuid()],true)||(fileperms($path)&0077)||filesize($path)>32768)throw new \RuntimeException('Protected publisher policy required');
        for($parent=dirname($path);; $parent=dirname($parent)){if(is_link($parent)||(fileperms($parent)&0022))throw new \RuntimeException('Publisher policy ancestry is writable');if($parent==='/')break;}
        return json_decode(file_get_contents($path),true,24,JSON_THROW_ON_ERROR);
    }
    public function authorize(array $request,string $publicKey,string $signature,int $now): array
    {
        Policy::hex($publicKey);$keys=array_keys($request);sort($keys);
        if($keys!==['actions','chain_id','expires_at','genesis_hash','issued_at','nonce','policy_hash','repository','version']||$request['version']!==1||$request['chain_id']!==$this->chain||$request['genesis_hash']!==$this->genesis||$request['policy_hash']!==hash('sha256',CanonicalJson::encode($this->policy)))throw new \InvalidArgumentException('Exact publisher request binding required');
        Policy::hex($request['nonce']);
        if(!is_int($request['issued_at'])||!is_int($request['expires_at'])||$request['issued_at']>$now||$request['expires_at']<=$now||$request['expires_at']-$request['issued_at']>120||$request['expires_at']>$this->policy['expires_at']||!in_array($request['actions'],[['pull'],['pull','push']],true))throw new \InvalidArgumentException('Publisher request time/scope invalid');
        $allowed=array_map(fn($id)=>'chains/'.$this->chain.'/system-services/'.$id,$this->policy['publishers'][$publicKey]??[]);
        if(!in_array($request['repository'],$allowed,true)||!preg_match('/^[a-f0-9]{128}$/D',$signature)||!sodium_crypto_sign_verify_detached(hex2bin($signature),ProtocolHash::hash('GREENWOOD_SYSTEM_SERVICE_PUBLISH_V1',CanonicalJson::encode($request)),hex2bin($publicKey)))throw new \InvalidArgumentException('Network publisher signature or service grant denied');
        $this->db->exec('BEGIN IMMEDIATE');try{
            $last=$this->db->query('SELECT last_time FROM system_publisher_clock WHERE id=1')->fetchColumn();if($last!==false&&$now<(int)$last)throw new \RuntimeException('Publisher clock rollback requires reconciliation');
            $clock=$this->db->prepare('INSERT OR REPLACE INTO system_publisher_clock VALUES(1,?)');$clock->execute([$now]);
            // Expired request tombstones can be removed because their signed times can never be reused.
            $q=$this->db->prepare('DELETE FROM system_publisher_nonces WHERE expires<?');$q->execute([$now-120]);
            if((int)$this->db->query('SELECT COUNT(*) FROM system_publisher_nonces')->fetchColumn()>=100000)throw new \RuntimeException('Publisher authorization capacity reached');
            $q=$this->db->prepare('INSERT INTO system_publisher_nonces VALUES(?,?,?)');$q->execute([$publicKey,$request['nonce'],$request['expires_at']]);$this->db->exec('COMMIT');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
        $encode=static fn($v)=>rtrim(strtr(base64_encode(json_encode($v,JSON_THROW_ON_ERROR)),'+/','-_'),'=');
        $body=$encode(['alg'=>'EdDSA','typ'=>'JWT']).'.'.$encode(['iss'=>'greenwood-registry-auth','sub'=>$publicKey,'aud'=>['greenwood-registry'],'iat'=>$now,'nbf'=>$now,'exp'=>$request['expires_at'],'jti'=>$request['nonce'],'access'=>[['type'=>'repository','name'=>$request['repository'],'actions'=>$request['actions']]]]);
        return ['token'=>$body.'.'.rtrim(strtr(base64_encode(sodium_crypto_sign_detached($body,sodium_crypto_sign_secretkey($this->issuerKeypair))),'+/','-_'),'='),'expires_in'=>$request['expires_at']-$now,'issued_at'=>gmdate('Y-m-d\TH:i:s\Z',$now)];
    }
}
