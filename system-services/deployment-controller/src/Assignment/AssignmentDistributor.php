<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Assignment;
use Greenwood\ControlPlane\MutualTlsChannel;

/** Two-node distribution journal; admission requires fresh matching commit acknowledgments. */
final class AssignmentDistributor {
    public function __construct(private readonly \PDO $db,private readonly array $nodes,
        private readonly \Closure $authorize,private readonly \Closure $send) {
        if(count($nodes)!==2||count(array_unique($nodes))!==2)throw new \InvalidArgumentException('Two distinct assignment stores required');
        $db->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);$db->exec('PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000');
        $db->exec('CREATE TABLE IF NOT EXISTS distribution(generation TEXT PRIMARY KEY, document TEXT NOT NULL, state TEXT NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS distribution_ack(generation TEXT NOT NULL, node TEXT NOT NULL, evidence TEXT NOT NULL, PRIMARY KEY(generation,node))');
    }
    public static function mtls(MutualTlsChannel $channel,array $nodePolicy):\Closure {
        return static function(string $node,array $request)use($channel,$nodePolicy):array{
            $p=$nodePolicy[$node]??throw new \RuntimeException('UNAPPROVED_ASSIGNMENT_NODE');
            return $channel->request($p['endpoint'],$p['tls_name'],$p['identity'],$request);
        };
    }
    public function distribute(array $document):array {return $this->exchange($document,['prepare','commit','status']);}
    /** Fresh reconciliation without installing or replacing an assignment. */
    public function verify(array $document):array {return $this->exchange($document,['status']);}
    private function exchange(array $document,array $actions):array {
        $d=AssignmentDocument::validate($document);$generation=AssignmentDocument::generation($d);$this->permission($d);
        $q=$this->db->prepare("INSERT OR IGNORE INTO distribution VALUES(?,?,'PENDING')");$q->execute([$generation,AssignmentDocument::canonical($d)]);
        // Invalidate an old admission before doing I/O. Never reuse stored healthy ACKs.
        $q=$this->db->prepare("UPDATE distribution SET state='PENDING' WHERE generation=?");$q->execute([$generation]);
        $acks=[];
        foreach($actions as$action){
            foreach($this->nodes as$node){
                $this->permission($d);$now=time();$nonce=bin2hex(random_bytes(32));
                $request=['action'=>$action,'document'=>$d,'nonce'=>$nonce,'issued_at'=>$now,'expires_at'=>$now+30];
                $ack=($this->send)($node,$request);
                if(($ack['node_id']??null)!==$node||($ack['generation']??null)!==$generation||($ack['version']??null)!==$d['version']
                    ||($ack['assignment_id']??null)!==AssignmentDocument::assignmentId($d)||($ack['nonce']??null)!==$nonce
                    ||!is_int($ack['observed_at']??null)||$ack['observed_at']<$now||$ack['observed_at']>time()+2||time()-$ack['observed_at']>15
                    ||!in_array($ack['state']??null,$action==='prepare'?['PREPARED','COMMITTED']:['COMMITTED'],true))throw new \RuntimeException('ASSIGNMENT_ACK_MISMATCH');
                $this->permission($d);$q=$this->db->prepare('INSERT OR REPLACE INTO distribution_ack VALUES(?,?,?)');$q->execute([$generation,$node,json_encode($ack,JSON_THROW_ON_ERROR)]);$acks[$node]=$ack;
            }
        }
        $this->permission($d);foreach($acks as$ack)if(time()-$ack['observed_at']>15)throw new \RuntimeException('ASSIGNMENT_ACK_EXPIRED');
        $q=$this->db->prepare("UPDATE distribution SET state='ACKNOWLEDGED' WHERE generation=?");$q->execute([$generation]);
        return ['generation'=>$generation,'assignment_id'=>AssignmentDocument::assignmentId($d),'version'=>$d['version'],'nodes'=>$acks,'acknowledged'=>true];
    }
    private function permission(array $d):void {if(($this->authorize)($d)!==true)throw new \RuntimeException('DISTRIBUTION_AUTHORITY_UNAVAILABLE');}
}
