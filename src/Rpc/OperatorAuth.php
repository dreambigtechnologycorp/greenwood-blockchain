<?php
declare(strict_types=1);
namespace Greenwood\Rpc;

/**
 * Single operator auth policy. Bearer token from a protected file; constant-time
 * comparison; the token value is never logged or included in error details.
 */
final class OperatorAuth
{
    private readonly string $tokenDigest;

    private function __construct(string $token)
    {
        if (strlen($token) < 16 || strlen($token) > 256) throw new \InvalidArgumentException('Operator token must be 16..256 bytes');
        $this->tokenDigest = hash('sha256', $token);
    }

    /** Token source is a protected file (never a config literal, never logged). */
    public static function fromFile(string $path): self
    {
        clearstatcache(true, $path);
        $mode = @fileperms($path);
        if (!is_file($path) || $mode === false || ($mode & 0077) !== 0) {
            throw new \InvalidArgumentException('Operator token file must exist and be private (mode 0600 or stricter)');
        }
        $raw = file_get_contents($path, false, null, 0, 4097);
        if ($raw === false || strlen($raw) > 4096) throw new \InvalidArgumentException('Invalid operator token file');
        return new self(rtrim($raw, "\r\n"));
    }

    /** @param array<string,string> $headers lowercase header names */
    public function authorize(array $headers): void
    {
        $header = $headers['authorization'] ?? null;
        if ($header === null || !preg_match('/^Bearer ([^\s]{16,256})$/D', $header, $m)
            || !hash_equals($this->tokenDigest, hash('sha256', $m[1]))) {
            throw ApiError::unauthenticated();
        }
    }
}
