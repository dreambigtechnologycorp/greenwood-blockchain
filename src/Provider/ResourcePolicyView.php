<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Network\ResourcePolicyState;
use Greenwood\Economics\Policy;

/** Explicit provenance: a pinned node assertion is not local certificate verification. */
final readonly class ResourcePolicyView
{
    public function __construct(public string $chain,public string $genesis,public int $height,public string $stateRoot,public string $blockHash,public ResourcePolicyState $policies,public string $source)
    {
        foreach([$genesis,$stateRoot,$blockHash] as $hash)Policy::hex($hash);
        if($chain===''||strlen($chain)>128||$height<1||!in_array($source,['LOCAL_REPLAYED_CHAIN','PINNED_NODE_ASSERTION'],true))throw new \InvalidArgumentException('Bounded finalized policy view required');
    }
}
