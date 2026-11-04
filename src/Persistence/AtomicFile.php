<?php
declare(strict_types=1);
namespace Greenwood\Persistence;
final class AtomicFile
{
    public static function write(string $path, string $bytes): void
    {
        if (!is_dir(dirname($path)) && !mkdir(dirname($path),0700,true) && !is_dir(dirname($path))) throw new \RuntimeException('Cannot create directory');
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(8));
        $f = fopen($tmp,'xb');
        if (!$f) throw new \RuntimeException('Cannot create temporary file');
        try {
            chmod($tmp,0600);
            for ($offset = 0; $offset < strlen($bytes); $offset += $written) {
                $written = fwrite($f,substr($bytes,$offset));
                if ($written === false || $written === 0) throw new \RuntimeException('Short write');
            }
            if (!fflush($f) || !fsync($f)) throw new \RuntimeException('File sync failed');
        } finally { fclose($f); }
        if (!rename($tmp,$path)) throw new \RuntimeException('Atomic rename failed');
        $dir = fopen(dirname($path),'r');
        if (!$dir) throw new \RuntimeException('Cannot open directory');
        try { if (!fsync($dir)) throw new \RuntimeException('Directory sync failed'); } finally { fclose($dir); }
    }
}
