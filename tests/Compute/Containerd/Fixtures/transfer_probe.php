<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use Containerd\Services\Transfer\V1\TransferRequest;
use Containerd\Types\Platform;
use Containerd\Types\Transfer\ImageStore;
use Containerd\Types\Transfer\OCIRegistry;
use Containerd\Types\Transfer\UnpackConfiguration;
use Google\Protobuf\Any;
use Google\Protobuf\GPBEmpty;
use Greenwood\Compute\Containerd\Model\OciPlatform;
use Greenwood\Compute\Containerd\Ttrpc\TtrpcClient;

$reference = $argv[1] ?? 'docker.io/library/alpine:3.21';
$namespace = $argv[2] ?? 'greenwood-transfer-probe';
$transport = $argv[3] ?? 'ttrpc';
$host = OciPlatform::fromHost();
$platform = (new Platform())->setOs($host->os)->setArchitecture($host->architecture);
if ($host->variant !== null) { $platform->setVariant($host->variant); }
$source = new Any();
$source->pack((new OCIRegistry())->setReference($reference));
$destination = new Any();
$destination->pack((new ImageStore())->setName($reference)->setPlatforms([$platform])->setUnpacks([
    (new UnpackConfiguration())->setPlatform($platform)->setSnapshotter('overlayfs'),
]));
$request = (new TransferRequest())->setSource($source)->setDestination($destination);
if (($argv[4] ?? '') === 'containerd-typeurl') {
    $source->setTypeUrl('containerd.types.transfer.OCIRegistry');
    $destination->setTypeUrl('containerd.types.transfer.ImageStore');
}
try {
    if ($transport === 'grpc') {
        $connection = new Greenwood\Compute\Containerd\ContainerdConnection($namespace);
        $clients = new Greenwood\Compute\Containerd\ContainerdClientSet($connection);
        [$response, $status] = $clients->getTransfer()->Transfer($request, $connection->getMetadata(),
            ['timeout' => 120_000_000])->wait();
        if ($status->code !== 0) { throw new RuntimeException($status->details, $status->code); }
    } else {
        $response = (new TtrpcClient())->unary('containerd.services.transfer.v1.Transfer', 'Transfer',
            $request, GPBEmpty::class, ['containerd-namespace-ttrpc' => $namespace], 120);
    }
    echo json_encode(['transport' => $transport, 'response' => $response::class, 'image' => $reference,
        'source_type' => $source->getTypeUrl(), 'destination_type' => $destination->getTypeUrl(),
        'platform' => (string) $host, 'namespace' => $namespace], JSON_PRETTY_PRINT), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . ' (code ' . $e->getCode() . ")\n");
    exit(1);
}
