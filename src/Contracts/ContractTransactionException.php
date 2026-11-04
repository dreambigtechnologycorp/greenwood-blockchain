<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

/** A failed contract transaction rejects its candidate block, like Transfer. */
final class ContractTransactionException extends \InvalidArgumentException
{
    public function __construct(public readonly ContractReceipt $receipt) { parent::__construct($receipt->status); }
}
