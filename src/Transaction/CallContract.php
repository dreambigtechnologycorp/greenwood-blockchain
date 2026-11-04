<?php
declare(strict_types=1);
namespace Greenwood\Transaction;

use Greenwood\Codec\{Canonical as C,Reader};
use Greenwood\Contracts\{ContractLimits,ContractTransactionLimits as L};
use Greenwood\Crypto\Ed25519KeyPair;
final readonly class CallContract
{
    public function __construct(public string $contractId,public string $method,public string $calldata,public int $attachedValue,public int $gasLimit)
    {
        if (strlen($contractId)!==32 || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D',$method) || strlen($calldata)>L::MAX_CALLDATA_BYTES) throw new \InvalidArgumentException('Invalid CallContract payload');
        if ($attachedValue<0 || $gasLimit<0 || $gasLimit>ContractLimits::MAX_GAS) throw new \InvalidArgumentException('Invalid value/gas');
    }
    public function serialize(): string { return $this->contractId.C::encodeBytes($this->method).C::encodeBytes($this->calldata).C::encodeU64BE($this->attachedValue).C::encodeU64BE($this->gasLimit); }
    public static function decode(string $bytes): self
    {
        $r=new Reader($bytes,L::MAX_TRANSACTION_BYTES); $payload=new self($r->take(32),$r->bytes(L::MAX_METHOD_BYTES),$r->bytes(L::MAX_CALLDATA_BYTES),$r->u64(),$r->u64()); $r->end(); return $payload;
    }
    public function createEnvelope(string $chainId,string $sender,int $nonce,Ed25519KeyPair $key): Envelope
    {
        $tx=new Envelope(1,$chainId,$sender,$nonce,3,$this->serialize(),'', $key->getPublicKey());
        return $tx->setSignature($key->sign($tx->getSigningDigest()));
    }
    public function invocationInput(): string { return \Greenwood\Contracts\AbiEncoder::encodeCall($this->method, $this->calldata); }
    public static function invocationInputStatic(string $method, string $calldata): string
    {
        if (strlen($method) > L::MAX_METHOD_BYTES || strlen($calldata) > L::MAX_CALLDATA_BYTES) {
            throw new \InvalidArgumentException('Method or calldata exceeds size limits for invocation input');
        }
        return \Greenwood\Contracts\AbiEncoder::encodeCall($method, $calldata);
    }
}
