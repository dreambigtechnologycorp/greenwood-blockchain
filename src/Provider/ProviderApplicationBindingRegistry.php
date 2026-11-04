<?php
declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\Compute\Containerd\OciImageRuntimeConfig;
use PDO;

/** Provider-owned reservations, not proof that networking or health is ready. */
final class ProviderApplicationBindingRegistry
{
    private PDO $db;
    private int $base;
    private int $slots;

    public function __construct(string $directory, string $subnet, private readonly int $firstPort, int $lastPort)
    {
        [$ip,$prefix]=array_pad(explode('/',$subnet),2,'');
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) || !ctype_digit($prefix)
            || (int)$prefix<16 || (int)$prefix>28 || !preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/',$ip)
            || $firstPort<1024 || $lastPort>65535 || $lastPort<$firstPort) throw new \InvalidArgumentException('Invalid provider binding pool');
        $this->base=(int)sprintf('%u',ip2long($ip));
        $size=1 << (32-(int)$prefix);
        if($this->base % $size !== 0)throw new \InvalidArgumentException('Binding subnet must be network-aligned');
        $this->slots=min(intdiv($size,4),$lastPort-$firstPort+1);
        if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new \RuntimeException('Cannot create binding registry');
        $this->db=new PDO('sqlite:'.$directory.'/bindings.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS binding_policy (id INTEGER PRIMARY KEY CHECK(id=1), fingerprint TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS application_bindings (application_id INTEGER NOT NULL, service TEXT NOT NULL, slot INTEGER NOT NULL UNIQUE, application_port INTEGER NOT NULL, PRIMARY KEY(application_id,service))');
        $fingerprint=hash('sha256',json_encode([$subnet,$firstPort,$lastPort]));
        $q=$this->db->prepare('INSERT OR IGNORE INTO binding_policy VALUES (1,?)');$q->execute([$fingerprint]);
        if($this->db->query('SELECT fingerprint FROM binding_policy WHERE id=1')->fetchColumn()!==$fingerprint)throw new \RuntimeException('Binding pool changed; explicit migration required');
    }

    /** Runtime config MUST come from the authenticated immutable OCI resolver. */
    public function reserve(int $applicationId,string $service,OciImageRuntimeConfig $image, bool $allowDetachedPortChange = false):array
    {
        if($applicationId<1||$applicationId>0xffffffff||!preg_match('/^[a-z0-9][a-z0-9._-]{0,62}$/D',$service))throw new \InvalidArgumentException('Invalid application identity');
        $tcp=array_values(array_filter($image->exposedPorts,static fn($p)=>str_ends_with($p,'/tcp')));
        if(count($tcp)!==1)throw new \RuntimeException('V1 automatic HTTP binding requires exactly one OCI-exposed TCP port');
        $port=(int)explode('/',$tcp[0])[0];
        $this->db->exec('BEGIN IMMEDIATE');
        try{
            $q=$this->db->prepare('SELECT slot,application_port FROM application_bindings WHERE application_id=? AND service=?');$q->execute([$applicationId,$service]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if($row!==false){
                if((int)$row['application_port']!==$port) {
                    if (!$allowDetachedPortChange) throw new \RuntimeException('OCI port changed; binding reconciliation required');
                    $update=$this->db->prepare('UPDATE application_bindings SET application_port=? WHERE application_id=? AND service=?');
                    $update->execute([$port,$applicationId,$service]);
                }
                $slot=(int)$row['slot'];
            }else{
                $used=$this->db->query('SELECT slot FROM application_bindings ORDER BY slot')->fetchAll(PDO::FETCH_COLUMN);$slot=0;
                foreach($used as $taken){if((int)$taken!==$slot)break;$slot++;}
                if($slot>=$this->slots)throw new \RuntimeException('Provider binding capacity exhausted');
                $q=$this->db->prepare('INSERT INTO application_bindings VALUES (?,?,?,?)');$q->execute([$applicationId,$service,$slot,$port]);
            }
            $this->db->exec('COMMIT');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
        $suffix=substr(hash('sha256',$applicationId."\0".$service),0,10);
        return ['application_id'=>$applicationId,'service'=>$service,'state'=>'RESERVED',
            'host_veth'=>'gwa'.$suffix,'application_veth'=>'gwp'.$suffix,
            'host_address'=>long2ip($this->base+$slot*4+1).'/30',
            'application_address'=>long2ip($this->base+$slot*4+2).'/30',
            'ingress_port'=>$this->firstPort+$slot,'application_port'=>$port];
    }
}
