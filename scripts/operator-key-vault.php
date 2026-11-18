#!/usr/bin/env php
<?php
declare(strict_types=1);

// Human-operated only. Never invoke against production secrets from an AI session.
final class OperatorKeyVault
{
    private const MAGIC = "GWKV1\0";
    private const MAX = 16777216;
    // Fixed GWKV1 costs: do not change for existing vaults.
    private const OPS = 3;
    private const MEMORY = 268435456;

    public static function directory(string $path): string
    {
        clearstatcache();
        if (!str_starts_with($path, '/') || realpath($path) !== $path || !is_dir($path)) throw new RuntimeException('Canonical directory required');
        $s = lstat($path);
        if ($s['uid'] !== posix_geteuid() || ($s['mode'] & 0777) !== 0700) throw new RuntimeException('Private directory required');
        for ($p = dirname($path); ; $p = dirname($p)) {
            $s = lstat($p);
            if (!in_array($s['uid'], [0, posix_geteuid()], true) || (($s['mode'] & 0022) && !(($s['mode'] & 01000) && $s['uid'] === 0))) throw new RuntimeException('Unsafe ancestry');
            if ($p === '/') break;
        }
        return $path;
    }

    public static function outsideCheckout(string $directory): void
    {
        for ($p = $directory; ; $p = dirname($p)) {
            if (file_exists($p.'/.git') || is_link($p.'/.git') || $p === dirname(__DIR__)) throw new RuntimeException('Output inside checkout prohibited');
            if ($p === '/') break;
        }
    }

    public static function writeNew(string $path, string $bytes): void
    {
        self::directory(dirname($path));
        self::outsideCheckout(dirname($path));
        $f = @fopen($path, 'xb');
        if ($f === false) throw new RuntimeException('Destination exists or is unavailable');
        try {
            if (!chmod($path, 0600)) throw new RuntimeException('Permissions failed');
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $n = fwrite($f, substr($bytes, $offset));
                if ($n === false || $n === 0) throw new RuntimeException('Write failed');
                $offset += $n;
            }
            if (!fflush($f) || !fsync($f)) throw new RuntimeException('Durable write failed');
        } finally { fclose($f); }
    }

    public static function readPrivate(string $path): string
    {
        self::directory(dirname($path));
        $s = @lstat($path);
        if (!$s || ($s['mode'] & 0170000) !== 0100000 || ($s['mode'] & 0777) !== 0600 || $s['uid'] !== posix_geteuid() || $s['nlink'] !== 1 || $s['size'] > self::MAX) throw new RuntimeException('Unprotected vault');
        $f = fopen($path, 'rb');
        try {
            $actual = fstat($f);
            if ($actual['ino'] !== $s['ino'] || $actual['dev'] !== $s['dev']) throw new RuntimeException('Changed vault');
            $bytes = stream_get_contents($f, self::MAX + 1);
            if ($bytes === false || strlen($bytes) > self::MAX) throw new RuntimeException('Oversized vault');
            return $bytes;
        } finally { fclose($f); }
    }

    public static function plan(array $plan): array
    {
        if (($plan['version'] ?? null) !== 1 || !is_array($plan['entries'] ?? null) || !array_is_list($plan['entries']) || count($plan['entries']) < 1 || count($plan['entries']) > 100) throw new RuntimeException('Invalid plan');
        $seen = [];
        foreach ($plan['entries'] as $e) {
            if (!is_array($e) || !preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $e['name'] ?? '') || isset($seen[$e['name']]) || !in_array($e['format'] ?? '', ['greenwood-ed25519', 'sodium-ed25519', 'token', 'tls-rsa'], true)) throw new RuntimeException('Invalid entry');
            $seen[$e['name']] = true;
            if ($e['format'] === 'tls-rsa' && !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $e['dns_name'] ?? '')) throw new RuntimeException('Explicit TLS DNS identity required');
            if ($e['format'] === 'tls-rsa' && str_ends_with($e['dns_name'], '.invalid')) throw new RuntimeException('Replace TLS placeholder before generating');
        }
        return $plan['entries'];
    }

    public static function generate(array $plan): array
    {
        $private = []; $public = [];
        foreach (self::plan($plan) as $e) {
            $format = $e['format']; $metadata = ['format' => $format];
            if ($format === 'token') {
                $bytes = bin2hex(random_bytes(32))."\n";
            } elseif ($format === 'tls-rsa') {
                $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]);
                if ($key === false || !openssl_pkey_export($key, $bytes)) throw new RuntimeException('TLS generation failed');
                $details = openssl_pkey_get_details($key);
                $metadata['public_key_pem'] = $details['key'];
                $metadata['dns_name'] = $e['dns_name'];
                // Certificates and trust roots are issued separately by the approved CA.
                $csr = openssl_csr_new(['commonName' => $e['dns_name']], $key, ['digest_alg' => 'sha256']);
                if ($csr === false || !openssl_csr_export($csr, $csrPem)) throw new RuntimeException('CSR generation failed');
                $metadata['csr_pem'] = $csrPem;
                $metadata['certificate_required'] = true;
                $metadata['san_must_be_set_by_issuer'] = $e['dns_name'];
                unset($key);
            } else {
                $pair = sodium_crypto_sign_keypair();
                $pub = sodium_crypto_sign_publickey($pair); $secret = sodium_crypto_sign_secretkey($pair);
                $bytes = $format === 'greenwood-ed25519' ? $pub.$secret : $pair;
                $metadata['public_key_hex'] = bin2hex($pub);
                $metadata['public_key_sha256'] = hash('sha256', $pub);
                sodium_memzero($secret); sodium_memzero($pair);
            }
            $private[$e['name']] = ['format' => $format, 'base64' => base64_encode($bytes)];
            $public[$e['name']] = $metadata;
            sodium_memzero($bytes);
        }
        return ['version' => 1, 'authority_granted' => false, 'public' => $public, 'private' => $private];
    }

    public static function seal(array $payload, string $password): string
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES); $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = self::derive($password, $salt); $plain = json_encode($payload, JSON_THROW_ON_ERROR);
        try { return self::MAGIC.$salt.$nonce.sodium_crypto_secretbox($plain, $nonce, $key); }
        finally { sodium_memzero($key); sodium_memzero($plain); }
    }

    private static function derive(string $password, string $salt): string
    {
        return sodium_crypto_pwhash(32, $password, $salt, self::OPS, self::MEMORY, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }

    public static function open(string $blob, string $password): array
    {
        $n = strlen(self::MAGIC);
        if (!str_starts_with($blob, self::MAGIC) || strlen($blob) < $n + 16 + 24 + 16 || strlen($blob) > self::MAX) throw new RuntimeException('Invalid vault');
        $key = self::derive($password, substr($blob, $n, 16));
        try { $plain = sodium_crypto_secretbox_open(substr($blob, $n + 40), substr($blob, $n + 16, 24), $key); }
        finally { sodium_memzero($key); }
        if ($plain === false) throw new RuntimeException('Authentication failed');
        try { $payload = json_decode($plain, true, 32, JSON_THROW_ON_ERROR); }
        finally { sodium_memzero($plain); }
        if (($payload['version'] ?? null) !== 1 || !is_array($payload['private'] ?? null) || !is_array($payload['public'] ?? null)) throw new RuntimeException('Invalid payload');
        return $payload;
    }

    public static function password(bool $confirm): string
    {
        $tty = fopen('/dev/tty', 'r+');
        if ($tty === false || !posix_isatty($tty)) throw new RuntimeException('Interactive terminal required');
        $stty = static function (array $args) use ($tty): string {
            $p = proc_open(array_merge(['/usr/bin/stty'], $args), [0 => $tty, 1 => ['pipe','w'], 2 => ['file','/dev/null','w']], $pipes);
            if (!is_resource($p)) throw new RuntimeException('Terminal control failed');
            $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
            if (proc_close($p) !== 0) throw new RuntimeException('Terminal control failed');
            return trim($out);
        };
        $old = $stty(['-g']);
        try {
            $stty(['-echo']); fwrite($tty, 'Vault passphrase (at least 20 characters): ');
            $line = fgets($tty, 4096);
            if ($line === false || !str_ends_with($line, "\n")) throw new RuntimeException('Invalid passphrase input');
            $password = rtrim($line, "\r\n"); sodium_memzero($line); fwrite($tty, "\n");
            if (strlen($password) < 20) throw new RuntimeException('Passphrase too short');
            if ($confirm) {
                fwrite($tty, 'Confirm passphrase: '); $line = fgets($tty, 4096);
                if ($line === false || !str_ends_with($line, "\n")) throw new RuntimeException('Invalid confirmation');
                $other = rtrim($line, "\r\n"); sodium_memzero($line); fwrite($tty, "\n");
                $match = hash_equals($password, $other); sodium_memzero($other);
                if (!$match) throw new RuntimeException('Passphrases differ');
            }
            return $password;
        } finally { $stty([$old]); fclose($tty); }
    }
}

function operatorVaultMain(array $argv): int
{
    umask(0077);
    if (!extension_loaded('sodium') || !extension_loaded('posix') || !extension_loaded('openssl') || !extension_loaded('pcntl')) throw new RuntimeException('Required extensions unavailable');
    if (!posix_setrlimit(POSIX_RLIMIT_CORE, 0, 0)) throw new RuntimeException('Cannot disable core dumps');
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) pcntl_signal($signal, static function (): void { throw new RuntimeException('Interrupted'); });
    $command = $argv[1] ?? 'help';
    if ($command === 'help') {
        echo "Human-only key vault; no network calls or deployment.\ncreate PLAN_JSON NEW_DIRECTORY\nverify VAULT_FILE\nexport VAULT_FILE ENTRY_NAME NEW_PRIVATE_FILE\nPassphrases are read only from your terminal. Never run against production secrets in an AI session.\n";
        return 0;
    }
    if ($command === 'create' && count($argv) === 4) {
        if (!is_file($argv[2]) || filesize($argv[2]) > 65536) throw new RuntimeException('Bounded plan required');
        $plan = json_decode(file_get_contents($argv[2]), true, 16, JSON_THROW_ON_ERROR); OperatorKeyVault::plan($plan);
        $parent = OperatorKeyVault::directory(dirname($argv[3])); OperatorKeyVault::outsideCheckout($parent); $output = $argv[3];
        if (basename($output) === '.' || basename($output) === '..' || $output !== $parent.'/'.basename($output)) throw new RuntimeException('Canonical new directory required');
        $repo = dirname(__DIR__);
        if ($parent === $repo || str_starts_with($parent, $repo.'/') || file_exists($output) || is_link($output)) throw new RuntimeException('New directory outside checkout required');
        $password = OperatorKeyVault::password(true);
        try {
            $payload = OperatorKeyVault::generate($plan); $blob = OperatorKeyVault::seal($payload, $password);
            $verified = OperatorKeyVault::open($blob, $password);
            if ($verified !== $payload) throw new RuntimeException('Vault verification failed');
            if (!mkdir($output, 0700)) throw new RuntimeException('Cannot create directory');
            OperatorKeyVault::writeNew($output.'/keys.gwkv', $blob);
            if (OperatorKeyVault::open(OperatorKeyVault::readPrivate($output.'/keys.gwkv'), $password) !== $payload) throw new RuntimeException('Stored vault verification failed');
            OperatorKeyVault::writeNew($output.'/public-identities.json', json_encode(['version'=>1,'authority_granted'=>false,'entries'=>$payload['public']], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
        } finally { sodium_memzero($password); unset($payload, $verified); }
        echo "Created encrypted keys.gwkv and public-identities.json. No plaintext keys written.\n";
        return 0;
    }
    if (($command === 'verify' && count($argv) === 3) || ($command === 'export' && count($argv) === 5)) {
        $blob = OperatorKeyVault::readPrivate($argv[2]); $password = OperatorKeyVault::password(false);
        try { $payload = OperatorKeyVault::open($blob, $password); }
        finally { sodium_memzero($password); }
        if ($command === 'export') {
            if (!isset($payload['private'][$argv[3]])) throw new RuntimeException('Unknown entry');
            $bytes = base64_decode($payload['private'][$argv[3]]['base64'], true);
            if ($bytes === false) throw new RuntimeException('Invalid entry');
            try { OperatorKeyVault::writeNew($argv[4], $bytes); }
            finally { sodium_memzero($bytes); }
            echo "Exported one plaintext identity with mode 0600. Secure it before installation.\n";
        } else { echo "Vault authentication verified.\n"; }
        unset($payload); return 0;
    }
    throw new RuntimeException('Invalid command');
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { exit(operatorVaultMain($argv)); }
    catch (Throwable $e) { fwrite(STDERR, "Key-vault operation failed. Check arguments, permissions, plan and passphrase. No secrets printed. Partial output, if any, is retained; never overwrite it blindly.\n"); exit(1); }
}
