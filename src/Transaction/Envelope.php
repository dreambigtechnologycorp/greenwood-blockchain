<?php
declare(strict_types=1);
namespace Greenwood\Transaction;
use Greenwood\Codec\Canonical as C;
use Greenwood\Codec\Reader;
use Greenwood\Crypto\ProtocolHash;

final class Envelope
{
    public const VERSION = 1;
    public const TYPE_TRANSFER = 1;
    public const TYPE_DEPLOY_CONTRACT = 2;
    public const TYPE_CALL_CONTRACT = 3;
    public const TYPE_DEPLOY_WORKLOAD = 4;
    public const TYPE_UPDATE_WORKLOAD = 5;
    public const TYPE_STOP_WORKLOAD = 6;
    public const TYPE_COMPUTE_REQUEST = 7;
    public const TYPE_COMPUTE_ASSIGN = 8;
    public const TYPE_COMPUTE_RECEIPT = 9;
    /** Pass 4D: provider schedulable-capability registration/update. */
    public const TYPE_PROVIDER_CAPABILITY = 10;
    public function __construct(
        private int $version, private string $chainId, private string $sender,
        private int $nonce, private int $type, private string $payload,
        private string $signature = '', private string $senderPublicKey = ''
    ) {
        if ($version !== 1 || $chainId === '' || strlen($chainId) > 128 || strlen($sender) !== 32
            || !in_array($type,[1,2,3,7,8,9,10],true) || ($type===1 && strlen($payload)!==36)
            || strlen($payload)+142+strlen($chainId)>\Greenwood\Contracts\ContractTransactionLimits::MAX_TRANSACTION_BYTES || !in_array(strlen($signature), [0,64], true)
            || !in_array(strlen($senderPublicKey), [0,32], true)) throw new \InvalidArgumentException('Invalid transaction envelope');
        C::encodeU32BE($nonce);
    }
    public function getVersion(): int { return $this->version; }
    public function getChainId(): string { return $this->chainId; }
    public function getSender(): string { return $this->sender; }
    public function getSenderPublicKey(): string { return $this->senderPublicKey; }
    public function getNonce(): int { return $this->nonce; }
    public function getType(): int { return $this->type; }
    public function getPayload(): string { return $this->payload; }
    public function getSignature(): string { return $this->signature; }
    public function setSignature(string $signature): self
    {
        if (strlen($signature) !== 64) throw new \InvalidArgumentException('Invalid signature length');
        $this->signature = $signature;
        return $this;
    }
    public function getSigningBytes(): string
    {
        if (strlen($this->senderPublicKey) !== 32) throw new \InvalidArgumentException('Sender public key required');
        return chr($this->version) . C::encodeU16BE(strlen($this->chainId)) . $this->chainId
            . $this->sender . $this->senderPublicKey . C::encodeU32BE($this->nonce) . chr($this->type) . C::encodeBytes($this->payload);
    }
    public function getSigningDigest(): string { return ProtocolHash::hash('GREENWOOD_TX_SIGN_V1', $this->getSigningBytes()); }
    public function getCanonicalBytes(): string
    {
        if (strlen($this->signature) !== 64) throw new \InvalidArgumentException('Unsigned transaction');
        return $this->getSigningBytes() . C::encodeU16BE(64) . $this->signature;
    }
    public function getHash(): string { return ProtocolHash::hash('GREENWOOD_TX_V1', $this->getCanonicalBytes()); }
    public function verify(string $chainId): void
    {
        if ($chainId !== $this->chainId || strlen($this->signature) !== 64
            || ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $this->senderPublicKey) !== $this->sender
            || !sodium_crypto_sign_verify_detached($this->signature, $this->getSigningDigest(), $this->senderPublicKey)) {
            throw new \InvalidArgumentException('Invalid transaction authorization');
        }
    }
    public static function read(Reader $r): self
    {
        $version = $r->u8(); $length = $r->u16();
        if ($length > 128) throw new \InvalidArgumentException('Chain ID too large');
        $chain = $r->take($length); $sender = $r->take(32); $key = $r->take(32);
        $nonce = $r->u32(); $type = $r->u8(); $payload = $r->bytes($type===1?36:\Greenwood\Contracts\ContractTransactionLimits::MAX_TRANSACTION_BYTES);
        if ($r->u16() !== 64) throw new \InvalidArgumentException('Invalid signature length');
        return new self($version, $chain, $sender, $nonce, $type, $payload, $r->take(64), $key);
    }
    public static function decode(string $bytes): self
    {
        $r = new Reader($bytes, \Greenwood\Contracts\ContractTransactionLimits::MAX_TRANSACTION_BYTES); $tx = self::read($r); $r->end(); return $tx;
    }
    public static function createTransfer(string $chainId, string $senderAddress, int $nonce, string $recipientAddress, int $amount, string $senderPublicKey = ''): self
    {
        return new self(1, $chainId, $senderAddress, $nonce, 1, (new Transfer($recipientAddress, $amount))->serialize(), '', $senderPublicKey);
    }
}
