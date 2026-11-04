<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Assignment;
use Greenwood\ControlPlane\MutualTlsChannel;

/** Framed mTLS receiver; listener placement belongs to protected service configuration. */
final class AssignmentEndpoint {
    public function __construct(private readonly MutualTlsChannel $channel,private readonly AssignmentReceiver $receiver) {}
    public function serveOne(mixed $listener):void {
        $this->channel->serveOne($listener,$this->receiver->handle(...));
    }
}
