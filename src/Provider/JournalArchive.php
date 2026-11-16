<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Economics\{CanonicalJson,Policy};

/** Write-before-prune immutable local evidence; archive files must be retained/backed up. */
final class JournalArchive
{
    public function __construct(private readonly string $directory)
    {
        if(!file_exists($directory)&&!is_link($directory)){if(realpath(dirname($directory))!==dirname($directory))throw new \RuntimeException('Canonical archive parent required');return;}
        if(realpath($directory)!==$directory||is_link($directory)||fileowner($directory)!==posix_geteuid()||(fileperms($directory)&0077)!==0)throw new \RuntimeException('Private canonical archive directory required');
    }
    public function put(array $body): string
    {
        if(!is_dir($this->directory)&&!mkdir($this->directory,0700)&&!is_dir($this->directory))throw new \RuntimeException('Archive directory unavailable');
        new self($this->directory);
        $raw=CanonicalJson::encode($body);if(strlen($raw)>16777216)throw new \RuntimeException('Archive record too large; shorter intervals required');
        $hash=hash('sha256',$raw);$path=$this->directory.'/'.$hash.'.json';
        if(file_exists($path)||is_link($path)){if(CanonicalJson::encode($this->get($hash))!==$raw)throw new \RuntimeException('Archive content mismatch');return $hash;}
        $tmp=$this->directory.'/.pending-'.bin2hex(random_bytes(16));$f=fopen($tmp,'x+b');if(!$f)throw new \RuntimeException('Archive creation failed');chmod($tmp,0600);
        try{$offset=0;while($offset<strlen($raw)){$n=fwrite($f,substr($raw,$offset));if(!$n)throw new \RuntimeException('Archive write failed');$offset+=$n;}
            if(!fflush($f)||!fsync($f))throw new \RuntimeException('Archive sync failed');fclose($f);$f=null;
            // link() never replaces an existing record. On crash, a redundant temp is harmless.
            if(!link($tmp,$path)){if(CanonicalJson::encode($this->get($hash))!==$raw)throw new \RuntimeException('Archive publication failed');}
            unlink($tmp);$dir=fopen($this->directory,'r');if(!$dir)throw new \RuntimeException('Archive directory sync unavailable');try{if(!fsync($dir))throw new \RuntimeException('Archive directory sync failed');}finally{fclose($dir);}
        }finally{if(is_resource($f))fclose($f);if(file_exists($tmp))unlink($tmp);}
        return $hash;
    }
    public function get(string $hash): array
    {
        Policy::hex($hash);$path=$this->directory.'/'.$hash.'.json';
        if(is_link($path)||!is_file($path)||fileowner($path)!==posix_geteuid()||(fileperms($path)&0077)!==0||filesize($path)>16777216)throw new \RuntimeException('Protected archive missing or unsafe');
        $raw=file_get_contents($path);if($raw===false||!hash_equals($hash,hash('sha256',$raw)))throw new \RuntimeException('Archive integrity failure');$body=json_decode($raw,true,25,JSON_THROW_ON_ERROR);if(!is_array($body)||CanonicalJson::encode($body)!==$raw)throw new \RuntimeException('Noncanonical journal archive');return $body;
    }
}
