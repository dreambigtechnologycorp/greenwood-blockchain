<?php
declare(strict_types=1);
namespace Greenwood\Transaction;
use Greenwood\Codec\{Canonical,Reader};

/** Separate type preserves the frozen 36-byte V1 transfer format. */
final class TransferU64
{
    public function __construct(public readonly string $recipient, public readonly int $amount)
    {
        if (strlen($recipient)!==32 || $amount<1) throw new \InvalidArgumentException('Invalid u64 transfer');
        Canonical::encodeU64BE($amount);
    }
    public function serialize(): string { return $this->recipient.Canonical::encodeU64BE($this->amount); }
    public static function validate(Envelope $tx,string $sender,int $nonce,int $balance,string $chain): array
    {
        $tx->verify($chain);$r=new Reader($tx->getPayload(),40);$to=$r->take(32);$amount=$r->u64();$r->end();
        if ($tx->getType()!==Envelope::TYPE_TRANSFER_U64 || $tx->getSender()!==$sender || $tx->getNonce()!==$nonce || $amount<1 || $amount>$balance) throw new \InvalidArgumentException('Invalid u64 transfer state');
        return ['valid'=>true,'recipient'=>$to,'amount'=>$amount];
    }
}
