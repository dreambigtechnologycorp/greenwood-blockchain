<?php
declare(strict_types=1);
$entry=dirname(__DIR__).'/public/index.php';
foreach(['/live'=>200,'/health'=>200,'/ready'=>503,'/metrics'=>404,'/v1/developer/capabilities'=>200,'/v1/developer/deployments'=>503]as$path=>$expected){
    $code='putenv("GREENWOOD_CONTROLLER_CONFIG"); $_SERVER["REQUEST_URI"]=$argv[2]; $_SERVER["REQUEST_METHOD"]=$argv[3]; ob_start(); require $argv[1]; $body=ob_get_clean(); echo json_encode([http_response_code()?:200,json_decode($body,true)]);';
    $process=proc_open([PHP_BINARY,'-r',$code,$entry,$path,$path==='/v1/developer/deployments'?'POST':'GET'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $result=json_decode(stream_get_contents($pipes[1]),true,32,JSON_THROW_ON_ERROR);$errors=stream_get_contents($pipes[2]);foreach($pipes as$p)fclose($p);
    if(proc_close($process)!==0||$errors!==''||$result[0]!==$expected)throw new RuntimeException('HTTP gate failed: '.$path);
    if($path==='/v1/developer/capabilities'&&(in_array(true,$result[1]['features'],true)||$result[1]['deployment_enabled']!==false))throw new RuntimeException('Capabilities enabled writes');
    if($path==='/ready'&&$result[1]['ready']!==false)throw new RuntimeException('Readiness false positive');
}
echo "HTTP entry point: backend liveness, closed readiness, disabled capabilities/writes and hidden metrics passed\n";
