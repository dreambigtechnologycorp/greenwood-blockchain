<?php
declare(strict_types=1);
namespace Greenwood\Compute\Containerd;

use Greenwood\Compute\Runtime\ResourceUsage;

/** Capture the actual task cgroup while its PID exists; read counters before deletion. */
final readonly class CgroupAccounting
{
    private function __construct(private string $path) {}
    public static function forPid(int $pid): self
    {
        $raw = @file_get_contents('/proc/' . $pid . '/cgroup');
        if ($pid < 1 || $raw === false || !preg_match('~^0::(/[^\r\n]*)$~m', $raw, $m) || str_contains($m[1], '..')) throw new \RuntimeException('Task cgroup unavailable for accounting');
        return new self('/sys/fs/cgroup' . $m[1]);
    }
    /** Deterministic containerd cgroupfs path; survives instant task exit (unlike /proc/<pid>). */
    public static function forTask(string $namespace, string $containerId): self
    {
        if (in_array($namespace,['.','..'],true)||in_array($containerId,['.','..'],true)||!preg_match('~^[A-Za-z0-9_.-]+$~D', $namespace) || !preg_match('~^[A-Za-z0-9_.-]+$~D', $containerId)) throw new \RuntimeException('Task cgroup unavailable for accounting');
        $path = '/sys/fs/cgroup/' . $namespace . '/' . $containerId;
        if (!is_dir($path)) throw new \RuntimeException('Task cgroup unavailable for accounting');
        return new self($path);
    }
    public function usage(): ResourceUsage
    {
        $cpu = @file_get_contents($this->path . '/cpu.stat');
        $peak = @file_get_contents($this->path . '/memory.peak');
        if ($cpu === false || $peak === false || !preg_match('/^usage_usec ([0-9]+)$/m', $cpu, $m)) throw new \RuntimeException('Task resource counters unavailable');
        $micros = self::counter($m[1]); $memory = self::counter(trim($peak));
        return new ResourceUsage(memoryPeakBytes: $memory, cpuTimeMs: self::cpuMillis((string)$micros));
    }
    /** Provider-side observation only. Optional controllers remain explicitly unavailable. */
    public function observe(): array
    {
        clearstatcache(true,$this->path);$before=@stat($this->path);$boot=@file_get_contents('/proc/sys/kernel/random/boot_id');
        if($before===false||$boot===false)throw new \RuntimeException('Cgroup runtime identity unavailable');
        $cpu=@file_get_contents($this->path.'/cpu.stat');if($cpu===false||!preg_match('/^usage_usec ([0-9]+)$/m',$cpu,$match))throw new \RuntimeException('CPU observation unavailable');$cpuMs=self::cpuMillis($match[1]);$peak=@file_get_contents($this->path.'/memory.peak');$current=@file_get_contents($this->path.'/memory.current');$io=@file_get_contents($this->path.'/io.stat');
        $cpuMax=@file_get_contents($this->path.'/cpu.max');$cpuLimit=null;
        if($cpuMax!==false&&preg_match('/^([0-9]+) ([0-9]+)\s*$/D',$cpuMax,$limit)){
            $period=self::counter($limit[2]);if($period<1)throw new \RuntimeException('Invalid CPU quota period');
            $cpuLimit=\Greenwood\Economics\Amount::mulDiv(self::counter($limit[1]),1000,$period,false);
        }
        $memoryMax=@file_get_contents($this->path.'/memory.max');
        $monotonicMs=intdiv(hrtime(true),1000000);
        clearstatcache(true,$this->path);$after=@stat($this->path);
        if($after===false||$before['ino']!==$after['ino']||$before['dev']!==$after['dev'])throw new \RuntimeException('Cgroup incarnation changed while sampling');
        return ['version'=>1,'verification_level'=>'C','source'=>'local_cgroup_v2','runtime_identity'=>hash('sha256',trim($boot).':'.$before['dev'].':'.$before['ino'].':'.$this->path),
            'monotonic_ms'=>(string)$monotonicMs,'cpu_limit_millicores'=>$cpuLimit===null?null:(string)$cpuLimit,
            'memory_limit_bytes'=>$memoryMax===false||trim($memoryMax)==='max'?null:(string)self::counter(trim($memoryMax)),
            'cpu_ms'=>(string)$cpuMs,'memory_peak_bytes'=>$peak===false?null:(string)self::counter(trim($peak)),
            'memory_current_bytes'=>$current===false?null:(string)self::counter(trim($current)),
            'io_devices'=>$io===false?null:self::parseIoStat($io),'io_attribution'=>'cgroup_block_device_counters_not_tenant_storage_proof'];
    }
    public static function parseIoStat(string $raw): array
    {
        if(strlen($raw)>65536)throw new \RuntimeException('I/O observation too large');$out=[];
        foreach(explode("\n",trim($raw)) as $line){if($line==='')continue;$parts=preg_split('/ +/',$line);$id=array_shift($parts);
            if(!preg_match('/^[0-9]+:[0-9]+$/D',$id)||isset($out[$id]))throw new \RuntimeException('Invalid/duplicate block device');$row=[];
            foreach($parts as $part){if(!preg_match('/^([a-z_]+)=([0-9]+)$/D',$part,$match)||isset($row[$match[1]]))throw new \RuntimeException('Invalid block I/O counter');$row[$match[1]]=(string)self::counter($match[2]);}
            foreach(['rbytes','wbytes','rios','wios'] as $required)if(!isset($row[$required]))throw new \RuntimeException('Missing block I/O measurement');ksort($row,SORT_STRING);$out[$id]=$row;
        }ksort($out,SORT_STRING);return $out;
    }
    private static function counter(string $value): int
    {
        $max = (string)PHP_INT_MAX;
        if (!ctype_digit($value) || strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) throw new \OverflowException('Resource counter exceeds integer range');
        return (int)$value;
    }
    public static function cpuMillis(string $microseconds): int { return intdiv(self::counter($microseconds), 1000); }
}
