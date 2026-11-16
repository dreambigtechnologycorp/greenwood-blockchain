<?php
declare(strict_types=1);
namespace Greenwood\Resources\Metering;
use Greenwood\Economics\{CanonicalJson,Policy};

/** Read-only Linux character-device assignment check. No utilization inference.
 * A reviewed driver/device profile and allocation lease are still required.
 */
final class DeviceReservationCollector
{
    public static function observe(array $binding,int $pid): array
    {
        $keys=array_keys($binding);sort($keys);
        if($keys!==['asset_id','device_path','driver','driver_version_hash','profile_hash','resource_id','sysfs_path'])throw new \InvalidArgumentException('Exact reviewed device binding required');
        foreach(['asset_id','driver_version_hash','profile_hash'] as $key)Policy::hex($binding[$key]);
        if(!in_array($binding['resource_id'],['compute.gpu.reserved','compute.npu.reserved','compute.accelerator.reserved','compute.gpu.vram'],true)||$pid<1)throw new \InvalidArgumentException('Unsupported device reservation');
        $result=['asset_id'=>$binding['asset_id'],'available'=>false,'verification_level'=>'C','payable'=>false,'reason'=>'DEVICE_OR_DRIVER_UNAVAILABLE'];
        try{
            if(!preg_match('~^/dev/(dri/(renderD|card)[0-9]+|accel/accel[0-9]+|nvidia[0-9]+)$~D',$binding['device_path'])||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$binding['driver'])||$binding['profile_hash']===str_repeat('0',64))throw new \RuntimeException('Unsupported device or unapproved driver');
            $sys=realpath($binding['sysfs_path']);if($sys===false||$sys!==$binding['sysfs_path']||!str_starts_with($sys,'/sys/devices/'))throw new \RuntimeException('Missing physical device identity');
            $driver=realpath($sys.'/driver');if($driver===false||basename($driver)!==$binding['driver'])throw new \RuntimeException('Driver mismatch');
            $version=@file_get_contents('/sys/module/'.$binding['driver'].'/version',false,null,0,4097);
            if($version===false||strlen($version)>4096||hash('sha256',trim($version))!==$binding['driver_version_hash'])throw new \RuntimeException('Unqualified driver version');
            $host=@stat($binding['device_path']);$guest=@stat('/proc/'.$pid.'/root'.$binding['device_path']);
            if(!$host||!$guest||($host['mode']&0170000)!==0020000||($guest['mode']&0170000)!==0020000||$host['rdev']!==$guest['rdev'])throw new \RuntimeException('Device is not assigned to this runtime');
            // sysfs dev binds the reviewed device to this character node, not another installed GPU.
            $dev=@file_get_contents($sys.'/dev',false,null,0,64);
            $major=(($host['rdev']>>8)&0xfff)|(($host['rdev']>>32)&0xfffff000);
            $minor=($host['rdev']&0xff)|(($host['rdev']>>12)&0xffffff00);
            if($dev===false||trim($dev)!==$major.':'.$minor)throw new \RuntimeException('Device/sysfs attribution mismatch');
            return array_replace($result,['available'=>true,'reason'=>null,'source_identity'=>hash('sha256',CanonicalJson::encode($binding).':'.$host['rdev'])]);
        }catch(\Throwable){return $result;}
    }
}
