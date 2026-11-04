<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
use Greenwood\ControlPlane\SignedPolicySnapshot;
use Greenwood\DeploymentController\Assignment\{AllocationApproval,AssignmentDocument,PhysicalAssignmentServices};

// Renewal preserves approvals. Explicit root invocation can approve one reviewed export.
if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 0) throw new RuntimeException('Root Linux required');
umask(0077);
$config = PhysicalAssignmentServices::config(getenv('GREENWOOD_ASSIGNMENT_CONFIG') ?: '');
$base = $config['policy_publisher_directory'] ?? '';
if (!is_string($base) || !str_starts_with($base,'/')) throw new RuntimeException('Protected publisher path required');
if (!is_dir($base) || is_link($base) || fileowner($base) !== 0 || (fileperms($base) & 0077) !== 0) throw new RuntimeException('Unsafe publisher directory');
for ($directory = dirname($base); ; $directory = dirname($directory)) {
    if (is_link($directory) || !is_dir($directory) || fileowner($directory) !== 0 || (fileperms($directory) & 0022) !== 0)
        throw new RuntimeException('Unsafe publisher ancestry');
    if ($directory === '/') break;
}
foreach (['revocation_public_key', 'allocation_public_key'] as $field) {
    if (!is_string($config[$field] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $config[$field]))
        throw new RuntimeException('Valid publisher pin required');
}
if (hash_equals($config['revocation_public_key'], $config['allocation_public_key']))
    throw new RuntimeException('Independent policy signing keys required');
$lifetime=$config['publisher_validity_seconds']??300;
if(!is_int($lifetime)||$lifetime<30||$lifetime>min($config['allocation_max_age'],$config['revocation_max_age']))throw new RuntimeException('Invalid publisher lifetime');
$approval = null;
if ($argc !== 1) {
    if ($argc !== 4 || $argv[1] !== '--approve-assignment') throw new RuntimeException('Usage: receiver-policy-publisher.php [--approve-assignment protected-export.json exact-generation]');
    $path = $argv[2];
    if (!str_starts_with($path, '/')) throw new RuntimeException('Absolute approval export path required');
    for ($directory = dirname($path); ; $directory = dirname($directory)) {
        if (is_link($directory) || !is_dir($directory) || fileowner($directory) !== 0 || (fileperms($directory) & 0022) !== 0)
            throw new RuntimeException('Protected approval export directory required');
        if ($directory === '/') break;
    }
    if (!is_file($path) || is_link($path) || fileowner($path) !== 0 || (fileperms($path) & 0077) !== 0
        || filesize($path) > 65536) throw new RuntimeException('Protected root-owned assignment export required');
    $export = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    $physical = PhysicalAssignmentServices::build($config);
    $approval = AllocationApproval::validate($export, $argv[3], $physical['authorize']);
}
$lock = fopen($base . '/lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Publisher lock unavailable');
function atomic(string $path, string $bytes): void {
    $temp = tempnam(dirname($path), '.new-'); $f = fopen($temp, 'wb');
    try { chmod($temp,0600); if (fwrite($f,$bytes)!==strlen($bytes)||!fflush($f)||!fsync($f)) throw new RuntimeException('Sync failed'); }
    finally { fclose($f); }
    if (!rename($temp,$path)) throw new RuntimeException('Rename failed');
    $dir=fopen(dirname($path),'r');try {if(!fsync($dir))throw new RuntimeException('Directory sync failed');}finally{fclose($dir);}
}
$output = [];
foreach (['revocations','allocations'] as $purpose) {
    $keyPath = $base . '/' . $purpose . '.key';
    if (!is_file($keyPath) || is_link($keyPath) || fileowner($keyPath)!==0 || (fileperms($keyPath)&0077)!==0) throw new RuntimeException('Missing or unsafe existing publisher key');
    $key=file_get_contents($keyPath);
    if(strlen($key)!==SODIUM_CRYPTO_SIGN_KEYPAIRBYTES)throw new RuntimeException('Invalid keypair');
    $pin = $config[$purpose === 'revocations' ? 'revocation_public_key' : 'allocation_public_key'];
    if (!hash_equals($pin, bin2hex(sodium_crypto_sign_publickey($key)))) throw new RuntimeException('Publisher pin mismatch');
    $path=$base.'/'.$purpose.'.json';$revision=0;
    $entries=[];
    if(!is_file($path)||is_link($path)||fileowner($path)!==0||(fileperms($path)&0077)!==0)throw new RuntimeException('Existing protected snapshot required');
    {
        $old=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        if(!sodium_crypto_sign_verify_detached(hex2bin($old['signature']),SignedPolicySnapshot::signingBytes($old['payload']),sodium_crypto_sign_publickey($key)))throw new RuntimeException('Prior policy signature failed');
        $revision=$old['payload']['revision'];
        $entries=$old['payload'][$purpose==='revocations'?'revoked_sha256':'approved_generations'];
        if (($old['payload']['purpose']??null)!==$purpose || ($old['payload']['chain_id']??null)!=='greenwood-physical-v1'
            || !is_int($revision) || $revision<1 || $revision===PHP_INT_MAX || !is_array($entries) || !array_is_list($entries)) throw new RuntimeException('Invalid prior policy');
        foreach($entries as $entry) if(!is_string($entry)||!preg_match('/^[a-f0-9]{64}$/D',$entry)) throw new RuntimeException('Invalid prior entry');
    }
    if ($purpose === 'allocations' && $approval !== null) {
        // Recheck authority immediately before signing, after acquiring the publisher lock.
        if (($physical['authorize'])($approval) !== true) throw new RuntimeException('Finalized approval denied');
        $entries[] = AssignmentDocument::generation($approval);
        $entries = array_values(array_unique($entries)); sort($entries);
    }
    $payload=['schema_version'=>1,'purpose'=>$purpose,'chain_id'=>'greenwood-physical-v1','revision'=>$revision+1,
        'issued_at'=>time(),'valid_until'=>time()+$lifetime,($purpose==='revocations'?'revoked_sha256':'approved_generations')=>$entries];
    $record=['payload'=>$payload,'signature'=>bin2hex(sodium_crypto_sign_detached(SignedPolicySnapshot::signingBytes($payload),sodium_crypto_sign_secretkey($key)))];
    atomic($path,json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    $output[$purpose]=['public_key_hex'=>bin2hex(sodium_crypto_sign_publickey($key)),'snapshot'=>$record];
}
echo json_encode($output,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),"\n";
