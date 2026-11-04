<?php
declare(strict_types=1);

// Validate provisioned identity without emitting secret material or starting a node.
require $argv[1] . '/vendor/autoload.php';
use Greenwood\Node\{Configuration, ChainConfig};
use Greenwood\Crypto\Ed25519KeyPair;

try {
    [$genesis] = Configuration::load('/etc/greenwood/genesis.json');
    $data = json_decode(file_get_contents('/etc/greenwood/genesis.json'), true, 32, JSON_THROW_ON_ERROR);
    if ($data['chain_id'] !== $argv[3]) throw new RuntimeException('Chain mismatch');
    $validators = array_keys($data['validators']); sort($validators);
    if ($validators !== ['A', 'B', 'C']) throw new RuntimeException('Validator set mismatch');
    foreach (['validator.key', 'provider.key'] as $name) {
        $path = '/etc/greenwood/' . $name;
        if (is_link($path) || fileowner($path) !== 0 || (fileperms($path) & 0777) !== 0600) {
            throw new RuntimeException('Unprotected identity');
        }
        $bytes = file_get_contents($path);
        if (strlen($bytes) !== 96) throw new RuntimeException('Invalid key encoding');
        $key = Ed25519KeyPair::fromBytes($bytes);
        if (!hash_equals($key->getPublicKey(), sodium_crypto_sign_publickey_from_secretkey($key->getPrivateKey()))) {
            throw new RuntimeException('Key pair mismatch');
        }
        $challenge = 'GREENWOOD_FRESH_IDENTITY_CHECK_V1';
        if (!sodium_crypto_sign_verify_detached($key->sign($challenge), $challenge, $key->getPublicKey())) {
            throw new RuntimeException('Signing-key control check failed');
        }
        if ($name === 'validator.key' && bin2hex($key->getPublicKey()) !== ($data['validators'][$argv[2]]['public_key'] ?? null)) {
            throw new RuntimeException('Validator does not belong to genesis');
        }
        if ($name === 'provider.key' && bin2hex($key->getPublicKey()) !== $argv[4]) {
            throw new RuntimeException('Provider identity mismatch');
        }
    }
    ChainConfig::load('/etc/greenwood/chain.json');
    $token = '/etc/greenwood/operator.token';
    if (is_link($token) || fileowner($token) !== 0 || (fileperms($token) & 0777) !== 0600
        || strlen(trim(file_get_contents($token))) < 32) throw new RuntimeException('Invalid operator token');
    echo "IDENTITY_VALID\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Provisioned cluster identity validation failed\n");
    exit(1);
}
