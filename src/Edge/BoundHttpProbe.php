<?php
declare(strict_types=1);
namespace Greenwood\Edge;

/** Bounded, non-redirecting probes; an explicit source is used for private origins. */
final class BoundHttpProbe
{
    public static function fetch(string $url, string $host, ?string $source = null): array
    {
        if (!preg_match('/^[a-z0-9.-]+$/D', $host)
            || ($source !== null && !filter_var($source, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))) {
            throw new \InvalidArgumentException('Invalid probe identity');
        }
        $handle = curl_init($url);
        $bytes = '';
        try {
            $options = [CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 6,
                CURLOPT_HTTPHEADER => ['Host: ' . $host, 'Connection: close'],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $part) use (&$bytes): int {
                    if (strlen($bytes) + strlen($part) > 1048576) return 0;
                    $bytes .= $part;
                    return strlen($part);
                }];
            if ($source !== null) $options[CURLOPT_INTERFACE] = $source;
            curl_setopt_array($handle, $options);
            if (curl_exec($handle) === false) throw new \RuntimeException('HTTP_PROBE_UNAVAILABLE');
            return [(int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $bytes];
        } finally {
            curl_close($handle);
        }
    }
}
