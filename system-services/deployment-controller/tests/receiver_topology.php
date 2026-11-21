<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Greenwood\DeploymentController\Assignment\ReceiverTopology;
function ensureTopology(bool $ok):void {if(!$ok)throw new RuntimeException('Topology assertion failed');}
ensureTopology(ReceiverTopology::validate(array_reverse(ReceiverTopology::SEVEN_NODE))===ReceiverTopology::SEVEN_NODE);
foreach ([[], ['node-1'], ['node-6','node-5'], [...ReceiverTopology::SEVEN_NODE,'node-4'],
    [...ReceiverTopology::SEVEN_NODE,'node-7'], ['node-1',4], ['node-01','node-4']] as $bad) {
    try {ReceiverTopology::validate($bad);throw new RuntimeException('Accepted invalid topology');}
    catch(InvalidArgumentException $e) {ensureTopology($e->getMessage()==='INVALID_RECEIVER_TOPOLOGY');}
}
// Exercise the real write gate with all six dedicated URI pins.
require __DIR__.'/write_enablement.php';
$v=$c; $v['assignment_receivers']=[];
foreach (ReceiverTopology::SEVEN_NODE as $index=>$node) {
    $pin=hash('sha256','receiver-'.$node);
    $v['assignment_receivers'][$node]=['endpoint'=>'tcp://192.168.72.'.(100+$index).':19444','tls_name'=>$node.'.test','identity'=>'assignment-'.$node];
    $v['tls']['peer_identities'][$pin]='assignment-'.$node;
    $v['tls']['peer_uris'][$pin]='spiffe://'.\Greenwood\DeploymentController\Auth\DeploymentIdentity::chain().'/control-plane/assignment/'.$node;
}
ensureTopology((new \Greenwood\DeploymentController\Deployment\WriteEnablement($v))->enabled());
foreach (ReceiverTopology::SEVEN_NODE as $node) {
    $bad=$v;unset($bad['assignment_receivers'][$node]);denied($bad,'INVALID_RECEIVER_TOPOLOGY');
    $bad=$v;$bad['tls']['peer_uris'][hash('sha256','receiver-'.$node)]='spiffe://wrong-chain/control-plane/assignment/'.$node;
    denied($bad,'PHYSICAL_PEER_ROLE_PIN_REQUIRED');
}
$bad=$v;$bad['assignment_receivers']['node-7']['endpoint']=$bad['assignment_receivers']['node-6']['endpoint'];
denied($bad,'DISTINCT_RECEIVERS_REQUIRED');
echo "Seven-node topology: complete membership, gateway exclusion, unique endpoints and every receiver role pin passed\n";
