<?php

declare(strict_types=1);
namespace Greenwood\Edge;

/** Read-only input owned by the deployment controller, never written by providers. */
final class ApprovedDeploymentStore
{
    public function __construct(private readonly string $directory, private readonly ?string $chainId=null, private readonly ?string $registryId=null)
    {
        if (($chainId===null)!==($registryId===null)||($registryId!==null&&(!preg_match('/^[a-f0-9]{64}$/D',$registryId)||$chainId!== 'greenwood-physical-v1'))) throw new \InvalidArgumentException('Invalid assignment scope');
        if (is_link($directory) || !is_dir($directory) || (fileperms($directory) & 0022) !== 0) throw new \InvalidArgumentException('Assignment directory must be controller-owned and protected');
    }
    public function get(int $applicationId, string $service, int $releaseId): array
    {
        if ($applicationId < 1 || $releaseId < 1 || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $service)) throw new \InvalidArgumentException('Invalid deployment identity');
        $path = $this->directory . '/' . $applicationId . '-' . $service . '.json';
        if (is_link($path) || !is_file($path) || (fileperms($path) & 0022) !== 0 || !in_array(fileowner($path), [0, posix_geteuid()], true) || filesize($path) > 8192) throw new \RuntimeException('Approved deployment unavailable or writable by others');
        $a = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $keys = ['application_id','service','release_id','provider_id','provider_address','ingress_port','instance_id','image_digest','provider_endpoint','provider_tls_name'];
        if($this->registryId!==null){
            $keys[]='chain_id';$keys[]='registry_contract_id';
            if(($a['chain_id']??null)!==$this->chainId||($a['registry_contract_id']??null)!==$this->registryId)throw new \RuntimeException('Assignment scope mismatch');
        }
        if (!is_array($a) || array_diff(array_keys($a), $keys) || array_diff($keys, array_keys($a))
            || $a['application_id'] !== $applicationId || $a['service'] !== $service || $a['release_id'] !== $releaseId
            || !is_string($a['provider_id']) || !preg_match('/^[a-f0-9]{64}$/D', $a['provider_id'])
            || !is_string($a['image_digest']) || !preg_match('/^sha256:[a-f0-9]{64}$/D', $a['image_digest'])
            || !is_string($a['instance_id']) || !preg_match('/^[a-z0-9-]{1,100}$/D', $a['instance_id'])
            || !is_int($a['ingress_port']) || $a['ingress_port'] < 10000 || $a['ingress_port'] > 65535
            || !filter_var($a['provider_address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || !preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $a['provider_address'])
            || !is_string($a['provider_endpoint']) || !is_string($a['provider_tls_name'])) throw new \RuntimeException('Invalid approved deployment');
        ksort($a);
        $a['assignment_id'] = hash('sha256', json_encode($a, JSON_THROW_ON_ERROR));
        return $a;
    }
}
