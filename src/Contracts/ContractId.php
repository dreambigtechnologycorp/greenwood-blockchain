<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

use Greenwood\Crypto\ProtocolHash;
final readonly class ContractId
{
    public function __construct(public string $bytes)
    { if (strlen($bytes)!==32) throw new \InvalidArgumentException('ContractId must be 32 bytes'); }
    public static function fromWasm(string $wasm): self { return new self(ProtocolHash::hash('GREENWOOD_CONTRACT_V1',$wasm)); }
    public function toHex(): string { return bin2hex($this->bytes); }
}
