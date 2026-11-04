<?php
declare(strict_types=1);
namespace Greenwood\Transaction;
use Greenwood\Codec\Canonical;
use Greenwood\Crypto\Ed25519KeyPair;
final class Transfer
{
    public function __construct(private string $recipient, private int $amount)
    {
        if (strlen($recipient) !== 32 || $amount <= 0) throw new \InvalidArgumentException('Invalid transfer');
        Canonical::encodeU32BE($amount);
    }
    public function getRecipient(): string { return $this->recipient; }
    public function getAmount(): int { return $this->amount; }
    public function serialize(): string { return $this->recipient . Canonical::encodeU32BE($this->amount); }
    public function createEnvelope(string $chainId, string $senderAddress, int $nonce, Ed25519KeyPair $keyPair): Envelope
    {
        $tx = new Envelope(1, $chainId, $senderAddress, $nonce, 1, $this->serialize(), '', $keyPair->getPublicKey());
        return $tx->setSignature($keyPair->sign($tx->getSigningDigest()));
    }
    public static function validate(Envelope $tx, string $senderAddress, int $senderNonce, int $senderBalance, ?string $chainId = null): array
    {
        try {
            if ($chainId === null) throw new \InvalidArgumentException('Expected chain ID required');
            $tx->verify($chainId);
            $amount = unpack('N', substr($tx->getPayload(), 32))[1];
            if ($tx->getSender() !== $senderAddress || $tx->getNonce() !== $senderNonce || $amount <= 0 || $senderBalance < $amount) throw new \InvalidArgumentException('Invalid transfer state');
            return ['valid'=>true, 'error'=>null, 'recipient'=>substr($tx->getPayload(),0,32), 'amount'=>$amount];
        } catch (\InvalidArgumentException $e) { return ['valid'=>false, 'error'=>$e->getMessage()]; }
    }
}
