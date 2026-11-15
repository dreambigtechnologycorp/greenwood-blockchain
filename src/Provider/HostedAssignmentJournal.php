<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Economics\CanonicalJson;

/** Reads the existing assignment receiver's committed document; creates no new financial identity. */
final class HostedAssignmentJournal
{
    private readonly \PDO $db;
    public function __construct(string $path) {
        if(!str_starts_with($path,'/')||realpath($path)!==$path||is_link($path)||!is_file($path)||(fileperms($path)&0077)!==0
            ||!in_array(fileowner($path),[0,posix_geteuid()],true)||strpbrk($path,'?#')!==false)throw new \RuntimeException('Private assignment receiver journal required');
        $this->db=new \PDO('sqlite:file:'.$path.'?mode=ro',null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_TIMEOUT=>1]);
    }
    public function document(int $app,string $service,string $imageRef): array {
        $q=$this->db->prepare("SELECT document FROM generations WHERE app=? AND service=? AND state='COMMITTED' ORDER BY version DESC LIMIT 1");$q->execute([$app,$service]);$raw=$q->fetchColumn();
        if(!is_string($raw)||strlen($raw)>32768)throw new \RuntimeException('Committed hosted assignment unavailable');
        $d=CanonicalJson::decode($raw);$a=$d['assignment']??[];
        if(($a['application_id']??null)!==$app||($a['service']??null)!==$service||!str_ends_with($imageRef,'@'.($a['image_digest']??'INVALID')))throw new \RuntimeException('Runtime differs from committed hosted assignment');return $d;
    }
}
