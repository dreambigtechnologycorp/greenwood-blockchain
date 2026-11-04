<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Provider;
use Greenwood\ControlPlane\MutualTlsChannel;

/** Desired-state admission only; this response is never proof of published routing. */
final class EdgeControllerClient
{
    public function __construct(private readonly MutualTlsChannel $channel,
        private readonly string $endpoint, private readonly string $peerName, private readonly string $identity) {}
    public function requestRoute(int $applicationId, string $service, int $releaseId, string $action = 'publish'): array
    {
        if (!in_array($action, ['publish','planned-replacement','stop'], true)) throw new \InvalidArgumentException('Invalid route action');
        // One attempt only. Timeout requires protected route-state reconciliation.
        return $this->channel->request($this->endpoint, $this->peerName, $this->identity,
            ['action'=>$action,'application_id'=>$applicationId,'service'=>$service,'release_id'=>$releaseId]);
    }
}
