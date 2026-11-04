<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
use Greenwood\ControlPlane\SignedPolicySnapshot;
use Greenwood\DeploymentController\Assignment\AllocationPolicy;
use Greenwood\DeploymentController\Assignment\AssignmentDocument;
use Greenwood\DeploymentController\Auth\DeploymentIdentity;

function check(bool $value, string $label): void { if (!$value) throw new RuntimeException($label); }
function denied(Closure $work, string $label): void {
    try { $work(); } catch (Throwable) { return; }
    throw new RuntimeException($label);
}
umask(0077);
$root = sys_get_temp_dir() . '/gw-policy-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$key = sodium_crypto_sign_keypair();
$public = bin2hex(sodium_crypto_sign_publickey($key));
$path = $root . '/policy.json';
$payload = ['schema_version'=>1,'purpose'=>'revocations','chain_id'=>'greenwood-physical-v1',
    'revision'=>1,'issued_at'=>time(),'valid_until'=>time()+120,'revoked_sha256'=>[]];
$write = static function (array $p, ?string $signer = null) use ($key, $path): void {
    file_put_contents($path, json_encode(['payload'=>$p,'signature'=>bin2hex(sodium_crypto_sign_detached(
        SignedPolicySnapshot::signingBytes($p), sodium_crypto_sign_secretkey($signer ?? $key)))], JSON_THROW_ON_ERROR));
};
try {
    $write($payload);
    $reader = new SignedPolicySnapshot($path, $root.'/ledger.sqlite', 'revocations', $public, 300);
    check($reader->read()['revision'] === 1, 'Valid policy rejected');
    check($reader->read()['revision'] === 1, 'Identical revision rejected');
    $next = $payload; $next['revision'] = 2; $next['revoked_sha256'] = [str_repeat('a',64)]; $write($next);
    check($reader->read()['revision'] === 2, 'New revision rejected');
    unset($reader);
    $reader = new SignedPolicySnapshot($path, $root.'/ledger.sqlite', 'revocations', $public, 300);
    $write($payload); denied($reader->read(...), 'Rollback survived restart');
    $conflict = $next; $conflict['revoked_sha256'] = []; $write($conflict);
    denied($reader->read(...), 'Same-revision equivocation accepted');
    $write($next, sodium_crypto_sign_keypair()); denied($reader->read(...), 'Unknown signer accepted');
    foreach (['expired','future','overlong','purpose','chain','malformed'] as $case) {
        $bad = $next; $bad['revision'] = 3;
        if ($case === 'expired') $bad['valid_until'] = time()-1;
        if ($case === 'future') $bad['issued_at'] = time()+30;
        if ($case === 'overlong') $bad['valid_until'] = time()+1000;
        if ($case === 'purpose') $bad['purpose'] = 'allocations';
        if ($case === 'chain') $bad['chain_id'] = 'other';
        if ($case === 'malformed') $bad['revoked_sha256'] = ['bad'];
        $write($bad); denied($reader->read(...), 'Accepted '.$case);
    }
    $write($next);
    $record = json_decode(file_get_contents($path), true); $record['payload']['revision'] = 9;
    file_put_contents($path, json_encode($record)); denied($reader->read(...), 'Tampered signature accepted');
    unlink($path); denied($reader->read(...), 'Missing policy accepted');
    $document = ['chain_id'=>DeploymentIdentity::CHAIN,'registry_contract_id'=>DeploymentIdentity::REGISTRY,
        'operation_id'=>str_repeat('a',32),'account_id'=>str_repeat('b',64),'version'=>1,'previous_generation'=>null,
        'assignment'=>['chain_id'=>DeploymentIdentity::CHAIN,'registry_contract_id'=>DeploymentIdentity::REGISTRY,
            'application_id'=>9,'release_id'=>1,'service'=>'web','provider_id'=>str_repeat('c',64),
            'provider_address'=>'10.77.0.100','ingress_port'=>21009,'instance_id'=>'app-9-web',
            'image_digest'=>'sha256:'.str_repeat('d',64),'provider_endpoint'=>'tcp://10.77.0.100:19443',
            'provider_tls_name'=>'provider.test']];
    $generation = AssignmentDocument::generation($document);
    $allocation = ['schema_version'=>1,'purpose'=>'allocations','chain_id'=>'greenwood-physical-v1',
        'revision'=>1,'issued_at'=>time(),'valid_until'=>time()+120,'approved_generations'=>[$generation]];
    $write($allocation);
    $allocReader = new SignedPolicySnapshot($path, $root.'/ledger.sqlite', 'allocations', $public, 300);
    check($allocReader->read()['approved_generations'] === [$generation], 'Signed allocation rejected');
    $policy = new AllocationPolicy($path, $allocReader);
    check($policy->approved($document), 'Exact generation denied');
    $changed = $document; $changed['assignment']['provider_id'] = str_repeat('e',64);
    check(!$policy->approved($changed), 'Different generation approved');
    $allocation['revision']++; $allocation['approved_generations'] = []; $write($allocation);
    check(!$policy->approved($document), 'Removed allocation remains approved');
    echo "Signed policy: valid, repeat, restart rollback, equivocation, signer, tamper, expiry, future, lifetime, purpose, chain, missing and malformed checks passed\n";
} finally {
    unset($reader, $allocReader, $policy);
    foreach (glob($root.'/*') as $file) unlink($file);
    rmdir($root);
}
