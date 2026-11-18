<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Greenwood\Resources\ResourceCatalog;
$root=dirname(__DIR__,2);$script=$root.'/scripts/draft-hyperscaler-budget.php';
$run=static function(?string $path=null)use($script):array {
    $args=[PHP_BINARY,$script];if($path!==null)$args[]=$path;
    $proc=proc_open($args,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($proc)!==0)throw new RuntimeException($stderr);
    return json_decode($stdout,true,512,JSON_THROW_ON_ERROR);
};
$count=0;$check=static function(bool $v)use(&$count):void{++$count;if(!$v)throw new RuntimeException('Budget regression '.$count);};
$r=$run();
foreach(['light'=>[3,27340,10936],'baseline'=>[5,50650,20260],'growth'=>[9,93020,37208]] as $name=>[$bundles,$gru,$own]){
    $s=$r['scenarios'][$name];$check($s['cloud_bundles']===$bundles);$check($s['whole_gru_budget']===$gru);
    $check($s['dreambig_whole_gru_budget']===$own);$check($s['dreambig_whole_gru_budget']+$s['clients_whole_gru_budget']===$gru);
    $check($s['gwd_budget_base_units']===null);
    foreach($s['pricing_projection']['lines'] as $line)$check(is_array(ResourceCatalog::definition($line['resource_id'])));
}
$inputs=json_decode(file_get_contents($root.'/docs/economics/evidence/hyperscaler-pricing-2026-11-18/inputs.json'),true);
$tmp=tempnam(sys_get_temp_dir(),'greenwood-draft-budget-');
try{
    // Historical proposed 1 GWD/GRU is an explicit hypothetical quote input,
    // not a token-price default. No keys or signatures are generated.
    $inputs['gwd_quote_base_units_per_whole_gru']='1000000';file_put_contents($tmp,json_encode($inputs));
    $q=$run($tmp);$check($q['scenarios']['baseline']['gwd_budget_base_units']===50650000000);
    $check($r===json_decode(file_get_contents($root.'/docs/economics/evidence/hyperscaler-pricing-2026-11-18/results.json'),true));
}finally{unlink($tmp);}
echo "Draft hyperscaler budget: $count checks passed; no keys generated\n";
