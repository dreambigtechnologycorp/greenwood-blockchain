<?php
declare(strict_types=1);
namespace Greenwood\Consensus\Messages;
use Greenwood\Codec\{Canonical as C,Reader};
use Greenwood\Crypto\ProtocolHash;
/** Canonical signed consensus wire object. Certificates contain signed precommits. */
final class Message
{
    public const KINDS=['proposal'=>1,'prevote'=>2,'precommit'=>3];
    public function __construct(
        public readonly string $kind, public readonly string $chain, public readonly string $genesis,
        public readonly int $height, public readonly int $round, public readonly string $validator,
        public readonly string $target, public readonly string $block='', public readonly string $proof='', public readonly string $signature=''
    ) {
        if (!isset(self::KINDS[$kind]) || strlen($chain)<1 || strlen($chain)>128 || strlen($genesis)!==32
            || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,31}$/D',$validator) || !in_array(strlen($target),[0,32],true)
            || ($kind==='proposal' && (strlen($target)!==32 || $block===''))
            || ($kind!=='proposal' && ($block!=='' || $proof!=='')) || strlen($block)>1048576 || strlen($proof)>4096
            || !in_array(strlen($signature),[0,64],true)) throw new \InvalidArgumentException('Malformed consensus message');
        C::encodeU32BE($height); C::encodeU32BE($round);
    }
    public function unsigned(): string
    {
        return chr(self::KINDS[$this->kind]).C::encodeU16BE(strlen($this->chain)).$this->chain.$this->genesis.C::encodeU32BE($this->height).C::encodeU32BE($this->round)
            .chr(strlen($this->validator)).$this->validator.C::encodeBytes($this->target).C::encodeBytes($this->block).C::encodeBytes($this->proof);
    }
    public function digest(): string { return ProtocolHash::hash('GREENWOOD_'.strtoupper($this->kind).'_V1',$this->unsigned()); }
    public function signed(string $signature): self { return new self($this->kind,$this->chain,$this->genesis,$this->height,$this->round,$this->validator,$this->target,$this->block,$this->proof,$signature); }
    public function bytes(): string
    {
        if (strlen($this->signature)!==64) throw new \InvalidArgumentException('Unsigned consensus message');
        return $this->unsigned().$this->signature;
    }
    /**
     * Verify this consensus message is from an authorized validator in the canonical set.
     * 
     * The validator ID in the message MUST correspond to the public key that signed it,
     * and that public key MUST be in the canonical validator set.
     * 
     * This is the core permission boundary: a valid signature from an unauthorized key
     * does not make a message valid.
     * 
     * @param array $validators Canonical validator set from genesis
     * @param string $chain Chain ID to verify against
     * @param string $genesis Genesis hash to verify against
     * @throws \InvalidArgumentException if validator is not authorized
     */
    public function verify(array $validators,string $chain,string $genesis): void
    {
        // Check chain and genesis match
        if ($this->chain!==$chain || $this->genesis!==$genesis) {
            throw new \InvalidArgumentException('Invalid consensus authorization: chain or genesis mismatch');
        }
        
        // Validator ID must exist in canonical validator set
        if (!isset($validators[$this->validator])) {
            throw new \InvalidArgumentException('Invalid consensus authorization: validator ID not in canonical set');
        }
        
        // Get the authorized public key for this validator ID
        $authorizedPublicKey = $validators[$this->validator]['publicKey'];
        
        // Signature verification must match the authorized public key
        if (strlen($this->signature)!==64) {
            throw new \InvalidArgumentException('Invalid consensus authorization: missing signature');
        }
        
        if (!sodium_crypto_sign_verify_detached($this->signature,$this->digest(),$authorizedPublicKey)) {
            throw new \InvalidArgumentException('Invalid consensus authorization: signature does not match authorized validator key');
        }
    }
    public static function decode(string $bytes): self
    {
        $r=new Reader($bytes,1100000); $kind=array_search($r->u8(),self::KINDS,true);
        if ($kind===false) throw new \InvalidArgumentException('Unknown message kind');
        $length=$r->u16(); if ($length>128) throw new \InvalidArgumentException('Chain too large');
        $chain=$r->take($length); $genesis=$r->take(32); $height=$r->u32(); $round=$r->u32(); $id=$r->take($r->u8());
        $m=new self($kind,$chain,$genesis,$height,$round,$id,$r->bytes(32),$r->bytes(1048576),$r->bytes(4096),$r->take(64)); $r->end(); return $m;
    }
}
