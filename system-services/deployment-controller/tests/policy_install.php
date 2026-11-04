<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
use Greenwood\ControlPlane\SignedPolicySnapshot;
use Greenwood\DeploymentController\Assignment\PolicyInstaller;
umask(0077);
$dir = sys_get_temp_dir() . '/h8-policy-install-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
$keys = ['revocations' => sodium_crypto_sign_keypair(), 'allocations' => sodium_crypto_sign_keypair()];
$config = ['policy_ledger' => "$dir/policy.sqlite", 'tls' => ['revocations' => "$dir/revocations.json"],
    'approved_allocations_file' => "$dir/allocations.json", 'revocation_max_age' => 300, 'allocation_max_age' => 300,
    'revocation_public_key' => bin2hex(sodium_crypto_sign_publickey($keys['revocations'])),
    'allocation_public_key' => bin2hex(sodium_crypto_sign_publickey($keys['allocations']))];
function bundle(int $revision, array $keys, array $changes = []): string {
    static $now = null;
    $now ??= time();
    $out = [];
    foreach ($keys as $purpose => $key) {
        $payload = array_merge(['schema_version'=>1,'purpose'=>$purpose,'chain_id'=>'greenwood-physical-v1',
            'revision'=>$revision,'issued_at'=>$now,'valid_until'=>$now+300,
            ($purpose==='revocations'?'revoked_sha256':'approved_generations')=>[str_repeat('a',64)]], $changes);
        $out[$purpose] = ['public_key_hex'=>bin2hex(sodium_crypto_sign_publickey($key)), 'snapshot'=>[
            'payload'=>$payload,'signature'=>bin2hex(sodium_crypto_sign_detached(SignedPolicySnapshot::signingBytes($payload), sodium_crypto_sign_secretkey($key)))]];
    }
    return json_encode($out, JSON_THROW_ON_ERROR);
}
function reject(callable $fn, string $expected): void {
    try { $fn(); } catch (Throwable $e) {
        if ($e->getMessage() === $expected) return;
        throw $e;
    }
    throw new RuntimeException('Expected rejection: '.$expected);
}
try {
    $one = bundle(1, $keys);
    PolicyInstaller::install($config, $one);
    PolicyInstaller::install($config, $one); // Exact re-delivery is idempotent.
    $two = bundle(2, $keys);
    PolicyInstaller::install($config, $two);
    reject(fn()=>PolicyInstaller::install($config,$one),'POLICY_ROLLBACK_OR_EQUIVOCATION');
    $conflictExpiry = json_decode($two,true)['revocations']['snapshot']['payload']['valid_until'] - 1;
    reject(fn()=>PolicyInstaller::install($config,bundle(2,$keys,['valid_until'=>$conflictExpiry])),'POLICY_ROLLBACK_OR_EQUIVOCATION');
    reject(fn()=>PolicyInstaller::install($config,bundle(3,$keys,['valid_until'=>time()-1])),'STALE_OR_INVALID_SIGNED_POLICY');
    reject(fn()=>PolicyInstaller::install($config,bundle(3,$keys,['chain_id'=>'other'])),'STALE_OR_INVALID_SIGNED_POLICY');
    $bad = json_decode(bundle(3,$keys),true);
    $bad['revocations']['snapshot']['signature'] = str_repeat('0',128);
    reject(fn()=>PolicyInstaller::install($config,json_encode($bad)),'POLICY_SIGNATURE_REJECTED');
    $bad['revocations']['public_key_hex'] = str_repeat('0',64);
    reject(fn()=>PolicyInstaller::install($config,json_encode($bad)),'POLICY_ISSUER_MISMATCH');
    $p = json_decode(file_get_contents($config['approved_allocations_file']),true);
    if ($p['payload']['approved_generations'] !== [str_repeat('a',64)] || $p['payload']['revision']!==2) throw new RuntimeException('Installed policy changed after rejection');
    PolicyInstaller::install($config,bundle(3,$keys));
    echo "PASS: install, idempotency, advancement, rollback, equivocation, expiry, chain, signature, issuer, preservation, recovery\n";
} finally {
    foreach (glob($dir.'/*') as $path) unlink($path);
    rmdir($dir);
}
