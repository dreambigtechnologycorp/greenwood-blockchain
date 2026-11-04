<?php
declare(strict_types=1);
namespace Greenwood\Provider;

use Greenwood\Compute\Protocol\{ProviderId, ComputeReceiptV2};
use Greenwood\Crypto\{Ed25519KeyPair, ProtocolHash};
use Greenwood\Transaction\Envelope;

/** Supervisor-owned signing material; never serialized into a worker request. */
final class ProviderIdentity
{
    public readonly string $publicKey;
    public readonly string $providerId;
    public readonly string $accountId;

    private function __construct(private readonly Ed25519KeyPair $key)
    {
        $this->publicKey = $key->getPublicKey();
        $this->providerId = ProviderId::fromPublicKey($this->publicKey)->toBytes();
        $this->accountId = ProtocolHash::hash('GREENWOOD_ACCOUNT_V1', $this->publicKey);
    }

    public static function fromFile(string $path, ?string $expectedProviderId = null, ?string $expectedPublicKey = null): self
    {
        clearstatcache(true, $path);
        $mode = @fileperms($path);
        if (!is_file($path) || $mode === false || ($mode & 0077) !== 0) throw new \InvalidArgumentException('Provider key file must be private (mode 0600 or stricter)');
        $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes) !== 96) throw new \InvalidArgumentException('Provider key must use the existing 96-byte keypair format');
        $key = Ed25519KeyPair::fromBytes($bytes);
        // Re-derive from the seed: merely extracting the cached public-key suffix
        // would not detect a corrupted secret seed.
        $derived = sodium_crypto_sign_seed_keypair(substr($key->getPrivateKey(), 0, 32));
        if (!hash_equals(sodium_crypto_sign_secretkey($derived), $key->getPrivateKey())
            || !hash_equals(sodium_crypto_sign_publickey($derived), $key->getPublicKey())) throw new \InvalidArgumentException('Inconsistent provider keypair');
        $identity = new self($key);
        if (($expectedProviderId !== null && !hash_equals($expectedProviderId, $identity->providerId))
            || ($expectedPublicKey !== null && !hash_equals($expectedPublicKey, $identity->publicKey))) throw new \InvalidArgumentException('Configured provider identity does not match key');
        return $identity;
    }

    public function signReceipt(ComputeReceiptV2 $receipt): ComputeReceiptV2
    {
        $receipt = clone $receipt;
        $receipt->sign($this->key);
        if (!$receipt->verifySignature($this->publicKey)) throw new \InvalidArgumentException('Receipt does not belong to this provider');
        return $receipt;
    }

    public function receiptTransaction(string $chainId, int $nonce, ComputeReceiptV2 $receipt): Envelope
    {
        if (!$receipt->verifySignature($this->publicKey)) throw new \InvalidArgumentException('Invalid provider receipt signature');
        $tx = new Envelope(1, $chainId, $this->accountId, $nonce, Envelope::TYPE_COMPUTE_RECEIPT, $receipt->toSignedBytes(), '', $this->publicKey);
        $tx->setSignature($this->key->sign($tx->getSigningDigest()));
        $tx->verify($chainId);
        return $tx;
    }

    public function capabilityTransaction(string $chainId, int $nonce, \Greenwood\Compute\Protocol\ProviderCapability $capability): Envelope
    {
        if ($capability->providerId !== $this->providerId || $capability->providerPublicKey !== $this->publicKey) {
            throw new \InvalidArgumentException('Capability does not belong to this provider');
        }
        $tx = new Envelope(1, $chainId, $this->accountId, $nonce, Envelope::TYPE_PROVIDER_CAPABILITY, $capability->toBytes(), '', $this->publicKey);
        $tx->setSignature($this->key->sign($tx->getSigningDigest()));
        $tx->verify($chainId);
        return $tx;
    }

    public function __debugInfo(): array { return ['provider_id' => bin2hex($this->providerId), 'account_id' => bin2hex($this->accountId)]; }
    public function __serialize(): array { throw new \LogicException('Provider signing material must not be serialized'); }
}
