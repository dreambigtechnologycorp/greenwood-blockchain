<?php
declare(strict_types=1);
namespace Greenwood\Consensus\Messages;
use Greenwood\Codec\{Canonical as C,Reader};
final class Certificate
{
    /** @param Message[] $votes */
    public function __construct(public readonly array $votes) {}
    public function bytes(): string
    {
        $votes=$this->votes; usort($votes,fn($a,$b)=>strcmp($a->validator,$b->validator));
        $bytes=C::encodeU32BE(count($votes)); foreach ($votes as $v) $bytes.=C::encodeBytes($v->bytes()); return $bytes;
    }
    public static function decode(string $bytes): self
    {
        $r=new Reader($bytes,4096); $n=$r->u32();
        // Structural bound only; the strict >2/3 quorum is derived from the
        // validator set in verify() (Certificate::quorum).
        if ($n<2 || $n>256) throw new \InvalidArgumentException('Certificate vote count out of structural bounds');
        $votes=[]; for ($i=0;$i<$n;$i++) $votes[]=Message::decode($r->bytes(512)); $r->end();
        $c=new self($votes); if ($c->bytes()!==$bytes) throw new \InvalidArgumentException('Noncanonical certificate ordering'); return $c;
    }

    /**
     * Strict >2/3 quorum for a validator set of size n, equal voting power:
     * quorum(n) = intdiv(2*n, 3) + 1. For the canonical 3-validator network
     * (A/B/C) this is 3-of-3; for a 4-validator set it would be 3-of-4.
     */
    public static function quorum(int $validatorCount): int
    {
        if ($validatorCount<3) throw new \InvalidArgumentException('A quorum requires at least 3 validators');
        return intdiv(2*$validatorCount,3)+1;
    }

    public function verify(array $validators,string $chain,string $genesis,string $kind,int $height,string $target,?int $round=null): int
    {
        $n=count($validators);
        $quorum=self::quorum($n);
        if (count($this->votes)<$quorum || count($this->votes)>$n || strlen($target)!==32) throw new \InvalidArgumentException('No strict quorum');
        $seen=[];
        foreach ($this->votes as $v) {
            $v->verify($validators,$chain,$genesis); $round??=$v->round;
            if ($v->kind!==$kind || $v->height!==$height || $v->target!==$target || $v->round!==$round || isset($seen[$v->validator])) throw new \InvalidArgumentException('Invalid certificate vote');
            $seen[$v->validator]=true;
        }
        return $round;
    }
}
