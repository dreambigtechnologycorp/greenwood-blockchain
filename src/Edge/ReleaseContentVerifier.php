<?php
declare(strict_types=1);
namespace Greenwood\Edge;

/** Release-selected normalization; all non-timestamp bytes remain hash-bound. */
final class ReleaseContentVerifier
{
    public static function digest(string $body, string $mode = 'raw-sha256'): string
    {
        if (strlen($body) > 1048576) throw new \RuntimeException('CONTENT_TOO_LARGE');
        if ($mode === 'raw-sha256') return hash('sha256', $body);
        if ($mode !== 'tanstack-root-index-v1') throw new \RuntimeException('UNKNOWN_CONTENT_VERIFIER');
        foreach (["__root__\0", "\0\0"] as $identity) {
            $pattern = '/(\{i:"' . preg_quote($identity, '/') . '",u:)[0-9]{13}(,s:"success",ssr:!0\})/';
            $body = preg_replace($pattern, '${1}@timestamp@${2}', $body, -1, $count);
            if ($body === null || $count !== 1) throw new \RuntimeException('HYDRATION_SHAPE_MISMATCH');
        }
        return hash('sha256', $body);
    }

    public static function verify(string $body, array $evidence, \Closure $fetch): string
    {
        $mode = $evidence['body_verifier'] ?? 'raw-sha256';
        $digest = self::digest($body, $mode);
        if (!hash_equals($evidence['body_sha256'], $digest)) throw new \RuntimeException('RELEASE_HTML_MISMATCH');
        if ($mode !== 'raw-sha256') {
            $assets = $evidence['assets'] ?? [];
            if (!is_array($assets) || !$assets || count($assets) > 32) throw new \RuntimeException('RELEASE_ASSETS_REQUIRED');
            preg_match_all('~/assets/[a-zA-Z0-9_.-]+~', $body, $matches);
            $paths = array_values(array_unique($matches[0])); sort($paths);
            $approved = array_keys($assets); sort($approved);
            if ($paths !== $approved) throw new \RuntimeException('RELEASE_ASSET_SET_MISMATCH');
            foreach ($assets as $path => $hash) {
                if (!preg_match('~^/assets/[a-zA-Z0-9_-]+\.(?:js|css)$~D', $path)
                    || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) throw new \RuntimeException('INVALID_RELEASE_ASSET');
                [$status, $bytes] = $fetch($path);
                if ($status !== 200 || !is_string($bytes) || strlen($bytes) > 1048576
                    || !hash_equals($hash, hash('sha256', $bytes))) throw new \RuntimeException('RELEASE_ASSET_MISMATCH');
            }
        }
        return $digest;
    }
}
