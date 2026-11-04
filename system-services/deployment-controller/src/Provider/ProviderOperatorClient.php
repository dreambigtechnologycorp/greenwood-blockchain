<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Provider;
use Greenwood\Rpc\ProviderApplicationHttpApi;

/** The private application facade is bearer-authenticated and peer-restricted. */
final class ProviderOperatorClient
{
    public function __construct(private readonly string $endpoint, private readonly string $tokenFile)
    {
        if (!str_starts_with($endpoint, 'unix:///')) ProviderApplicationHttpApi::validateEndpoint($endpoint);
        elseif (!preg_match('~^unix:///run/[a-zA-Z0-9_./-]+\.sock$~D', $endpoint) || str_contains($endpoint, '..')) {
            throw new \InvalidArgumentException('Protected operator socket required');
        }
    }
    public function startApplication(int $applicationId, string $service, string $imageRef,
        #[\SensitiveParameter] string $pullToken, string $registryScheme = 'https'): array
    {
        if (!preg_match('/@sha256:[a-f0-9]{64}$/D', $imageRef)) throw new \InvalidArgumentException('Immutable image required');
        return $this->post('start', ['application_id'=>$applicationId,'service'=>$service,'image_ref'=>$imageRef,
            'pull_token'=>$pullToken,'registry_scheme'=>$registryScheme]);
    }
    public function inspectApplication(int $applicationId, string $service): array
    {
        return $this->post('inspect', ['application_id'=>$applicationId,'service'=>$service]);
    }
    public function reserveApplication(int $applicationId, string $service, string $imageRef): array
    {
        return $this->post('reserve', ['application_id'=>$applicationId,'service'=>$service,'image_ref'=>$imageRef]);
    }
    public function stopApplication(int $applicationId, string $service): array
    {
        return $this->post('stop', ['application_id'=>$applicationId,'service'=>$service]);
    }
    private function post(string $action, array $payload): array
    {
        clearstatcache(true, $this->tokenFile);
        if (is_link($this->tokenFile) || !is_file($this->tokenFile) || (fileperms($this->tokenFile)&0077)!==0
            || !in_array(fileowner($this->tokenFile),[0,posix_geteuid()],true)) throw new \RuntimeException('Unsafe operator credential file');
        $token=rtrim(file_get_contents($this->tokenFile), "\r\n");
        if (!preg_match('/^[^\s]{16,256}$/D',$token)) throw new \RuntimeException('Invalid operator credential');
        $socket=str_starts_with($this->endpoint,'unix:///')?substr($this->endpoint,7):null;
        if($socket!==null&&(is_link($socket)||filetype($socket)!=='socket'||fileowner($socket)!==0||(fileperms($socket)&0002)!==0))throw new \RuntimeException('Unsafe operator socket');
        $curl=curl_init(($socket===null?$this->endpoint:'http://localhost').'/v1/operator/provider/applications/'.$action);
        try {
            if($socket!==null)curl_setopt($curl,CURLOPT_UNIX_SOCKET_PATH,$socket);
            curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_PROXY=>'',
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,
                CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token]]);
            $raw=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if (!is_string($raw) || $status!==200) throw new \RuntimeException('Provider request unresolved; reconcile before any write retry');
            $result=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            if (!is_array($result) || array_is_list($result)) throw new \RuntimeException('Invalid provider response');
            return $result;
        } finally { curl_close($curl); }
    }
}
