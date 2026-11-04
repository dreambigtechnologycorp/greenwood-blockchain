<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Auth;
final class ChallengeService {
    public function __construct(private readonly \PDO $db) {}
    public static function intent(array $input):array {
        DeploymentIdentity::validate($input);
        $keys=['action','chain_id','registry_contract_id','application_id','service','release_number','image_digest','idempotency_key'];$i=array_intersect_key($input,array_flip($keys));
        if(count($i)!==8||$i['action']!=='deploy'||$i['chain_id']!=='greenwood-physical-v1'||!is_int($i['application_id'])||$i['application_id']<1||$i['application_id']>0xffffffff
            ||!is_int($i['release_number'])||$i['release_number']<1||$i['release_number']>0xffffffff||!is_string($i['service'])||!preg_match('/^[a-z][a-z0-9-]{0,62}$/D',$i['service'])
            ||!is_string($i['image_digest'])||!preg_match('/^sha256:[a-f0-9]{64}$/D',$i['image_digest'])||!is_string($i['idempotency_key'])||!preg_match('/^[a-zA-Z0-9_-]{8,128}$/D',$i['idempotency_key']))throw new \InvalidArgumentException('INVALID_INTENT');
        return $i;
    }
    public function issue(array $input):array {
        $i=self::intent($input);$now=time();$c=$i+['id'=>bin2hex(random_bytes(16)),'nonce'=>bin2hex(random_bytes(32)),'issued_at'=>$now,'expires_at'=>$now+90];
        $lines=['GREENWOOD-DEPLOYMENT-AUTH-V2'];foreach(['chain_id','registry_contract_id','action','application_id','service','release_number','image_digest','idempotency_key','nonce','issued_at','expires_at']as$k)$lines[]=$k.'='.$c[$k];
        $c['canonical_bytes']=implode("\n",$lines);
        $q=$this->db->prepare('INSERT INTO challenge VALUES(?,?,?)');$q->execute([$c['id'],json_encode($c,JSON_THROW_ON_ERROR),$c['expires_at']]);return $c;
    }
    public function verify(array $input):array {
        $i=self::intent($input);$q=$this->db->prepare('SELECT * FROM challenge WHERE id=?');$q->execute([$input['challenge_id']??'']);$row=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$row||$row['expires']<=time())throw new \RuntimeException('UNAUTHENTICATED');$c=json_decode($row['body'],true,32,JSON_THROW_ON_ERROR);
        foreach($i as$k=>$v)if($c[$k]!==$v)throw new \RuntimeException('INTENT_MISMATCH');
        $account=SignedRequestVerifier::signature($input['public_key']??'', $input['signature']??'', $c['canonical_bytes']);
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q=$this->db->prepare('DELETE FROM challenge WHERE id=? AND expires>?');$q->execute([$c['id'],time()]);if($q->rowCount()!==1)throw new \RuntimeException('REPLAY');
            // Durable verification attestation without retaining a raw signature or signed challenge.
            $stable=$i;ksort($stable);$hash=hash('sha256',json_encode($stable,JSON_THROW_ON_ERROR));
            $q=$this->db->prepare('INSERT INTO authenticated_intents VALUES(?,?,?,?)');$q->execute([$c['id'],$account,$hash,time()]);
            $this->db->exec('COMMIT');
        }catch(\Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
        return [$account,$i];
    }
}
