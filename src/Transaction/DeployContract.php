<?php
declare(strict_types=1);
namespace Greenwood\Transaction;

use Greenwood\Codec\{Canonical as C,Reader};
use Greenwood\Contracts\{ContractLimits,ContractTransactionLimits as L};
use Greenwood\Crypto\Ed25519KeyPair;
final readonly class DeployContract
{
    public function __construct(public string $wasm,public string $initCalldata,public int $attachedValue,public int $gasLimit)
    {
        if ($wasm==='' || strlen($wasm)>L::MAX_WASM_BYTES || strlen($initCalldata)>ContractLimits::MAX_INPUT_BYTES) throw new \InvalidArgumentException('Invalid DeployContract payload');
        if ($attachedValue<0 || $gasLimit<0 || $gasLimit>ContractLimits::MAX_GAS) throw new \InvalidArgumentException('Invalid value/gas');
    }
    public function serialize(): string { return C::encodeBytes($this->wasm).C::encodeBytes($this->initCalldata).C::encodeU64BE($this->attachedValue).C::encodeU64BE($this->gasLimit); }
    public static function decode(string $bytes): self
    {
        $r=new Reader($bytes,L::MAX_TRANSACTION_BYTES); $payload=new self($r->bytes(L::MAX_WASM_BYTES),$r->bytes(ContractLimits::MAX_INPUT_BYTES),$r->u64(),$r->u64()); $r->end(); return $payload;
    }
    public function createEnvelope(string $chainId,string $sender,int $nonce,Ed25519KeyPair $key): Envelope
    {
        $tx=new Envelope(1,$chainId,$sender,$nonce,2,$this->serialize(),'', $key->getPublicKey());
        return $tx->setSignature($key->sign($tx->getSigningDigest()));
    }
}
