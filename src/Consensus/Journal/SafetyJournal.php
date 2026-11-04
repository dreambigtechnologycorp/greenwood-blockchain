<?php
declare(strict_types=1);
namespace Greenwood\Consensus\Journal;
use Greenwood\Consensus\Messages\Message;
use Greenwood\Crypto\Ed25519KeyPair;
/** Safety database is independent of the rebuildable chain index. Never prune it. */
final class SafetyJournal
{
    private \PDO $db;
    public function __construct(string $path,private readonly Ed25519KeyPair $key,private readonly string $genesis,private readonly string $id)
    {
        $this->db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA synchronous=FULL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS identity (id INTEGER PRIMARY KEY, genesis BLOB, validator TEXT, public_key BLOB)');
        $identity=$this->db->query('SELECT * FROM identity')->fetch(\PDO::FETCH_ASSOC);
        if (!$identity) $this->db->prepare('INSERT INTO identity VALUES (1,?,?,?)')->execute([$genesis,$id,$key->getPublicKey()]);
        elseif ($identity['genesis']!==$genesis || $identity['validator']!==$id || $identity['public_key']!==$key->getPublicKey()) throw new \RuntimeException('Journal identity mismatch');
        $this->db->exec('CREATE TABLE IF NOT EXISTS signatures (height INTEGER, round INTEGER, kind TEXT, unsigned BLOB, signed BLOB, PRIMARY KEY(height,round,kind))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS locks (height INTEGER PRIMARY KEY, round INTEGER, block BLOB, proof BLOB)');
        // Verify persisted signatures before the key can sign again.
        foreach ($this->db->query('SELECT * FROM signatures') as $row) {
            $m=Message::decode($row['signed']);
            if ($m->genesis!==$genesis || $m->validator!==$id || $m->unsigned()!==$row['unsigned'] || $m->height!==(int)$row['height'] || $m->round!==(int)$row['round'] || $m->kind!==$row['kind'] || !sodium_crypto_sign_verify_detached($m->signature,$m->digest(),$key->getPublicKey())) throw new \RuntimeException('Corrupt signing journal');
        }
    }
    public function sign(Message $m): Message
    {
        if ($m->validator!==$this->id || $m->genesis!==$this->genesis) throw new \RuntimeException('Wrong signing identity');
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q=$this->db->prepare('SELECT * FROM signatures WHERE height=? AND round=? AND kind=?'); $q->execute([$m->height,$m->round,$m->kind]); $row=$q->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                if ($row['unsigned']!==$m->unsigned()) throw new \RuntimeException('Conflicting signing attempt');
                $signed=Message::decode($row['signed']);
            } else {
                $signed=$m->signed($this->key->sign($m->digest()));
                $this->db->prepare('INSERT INTO signatures VALUES (?,?,?,?,?)')->execute([$m->height,$m->round,$m->kind,$m->unsigned(),$signed->bytes()]);
            }
            $this->db->exec('COMMIT'); return $signed;
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    public function messages(int $height): array
    {
        $q=$this->db->prepare('SELECT signed FROM signatures WHERE height=? ORDER BY round,kind'); $q->execute([$height]);
        return array_map(fn($r)=>Message::decode($r['signed']),$q->fetchAll(\PDO::FETCH_ASSOC));
    }
    public function lock(int $height,int $round,string $block,string $proof): void
    {
        $old=$this->getLock($height);
        if ($old && ((int)$old['round']>$round || ((int)$old['round']===$round && $old['block']!==$block))) throw new \RuntimeException('Conflicting lock');
        $this->db->prepare('INSERT OR REPLACE INTO locks VALUES (?,?,?,?)')->execute([$height,$round,$block,$proof]);
    }
    public function getLock(int $height): ?array
    {
        $q=$this->db->prepare('SELECT * FROM locks WHERE height=?'); $q->execute([$height]); return $q->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}
