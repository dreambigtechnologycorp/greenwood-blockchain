<?php
declare(strict_types=1);
// Offline draft calculator. No keys, signing, network calls or production changes.
require __DIR__.'/economics-bootstrap.php';
use Greenwood\Economics\Amount;
use Greenwood\Resources\ResourceManifest;
$path=$argv[1]??dirname(__DIR__).'/docs/economics/evidence/hyperscaler-pricing-2026-11-18/inputs.json';
$p=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
if($p['status']!=='DRAFT_NOT_APPROVED'||$p['hours']!==720||$p['gru_scale']!==1000000)throw new InvalidArgumentException('Expected 30-day draft reference inputs');
function gcdBudget(int $a,int $b): int {while($b){[$a,$b]=[$b,$a%$b];}return $a;}
function budgetLine(string $id,string $resource,int $quantity,int $units,int $cost): array {
    $g=gcdBudget($cost,$quantity);
    return ['line_id'=>$id,'resource_id'=>$resource,'reserved_units'=>(string)$units,'maximum_quantity'=>(string)$quantity,
        'rate_num'=>(string)intdiv($cost,$g),'rate_den'=>(string)intdiv($quantity,$g),'included_quantity'=>'0'];
}
$out=['status'=>'DRAFT_NOT_APPROVED','days'=>30,'gru_scale'=>$p['gru_scale'],'gwd_price_assumed'=>false,'scenarios'=>[]];
$duration=$p['hours']*3600000;
foreach($p['scenarios'] as $name=>$s){
    $cpu=$p['apps']*$s['app_millicores']+$s['shared_millicores'];
    $ram=$p['apps']*$s['app_ram_mib']+$s['shared_ram_mib'];
    $bundles=max(intdiv($cpu+1999,2000),intdiv($ram+8191,8192));
    $totals=[];$compute=[];$disk=[];
    foreach($p['providers'] as $provider=>$rate){
        // Amount::parse supplies six-decimal integer arithmetic, here USD micros.
        $compute[$provider]=Amount::multiply(Amount::parse($rate['vm_hour_usd']),$p['hours']*$bundles);
        $disk[$provider]=Amount::multiply(Amount::parse($rate['disk_128_gib_month_usd']),$bundles);
        $totals[$provider]=Amount::add($compute[$provider],$disk[$provider]);
    }
    asort($totals,SORT_NUMERIC);$median=array_keys($totals)[1];
    $egress=Amount::multiply(Amount::parse($p['common_ancillary_reference']['egress_usd_per_gib']),$s['egress_gib']);
    $backup=Amount::multiply(Amount::parse($p['common_ancillary_reference']['backup_usd_per_gib_month']),$s['backup_gib']);
    // 1 reference whole GRU = 10,000 USD micros. 1,000,000 atomic GRU/whole.
    $toGru=fn(int $micros)=>Amount::mulDiv($micros,$p['gru_scale'],10000,true);
    $lines=[budgetLine('cpu_bundle','compute.cpu.reserved',$bundles*2000*$duration,$bundles*2000,$toGru($compute[$median])),
        budgetLine('ram_included','memory.ram.reserved',$bundles*8192*$duration,$bundles*8192,0),
        budgetLine('storage','storage.ssd.capacity',$bundles*128*1024*$duration,$bundles*128*1024,$toGru($disk[$median])),
        budgetLine('transfer','network.egress',$s['egress_gib']*1073741824,1,$toGru($egress)),
        budgetLine('backup','backup.snapshot.capacity',$s['backup_gib']*1024*$duration,$s['backup_gib']*1024,$toGru($backup))];
    usort($lines,fn($a,$b)=>strcmp($a['line_id'],$b['line_id']));
    // Pricing projection only: intentionally lacks asset/capability identities and
    // is NOT a deployable ResourceManifest or qualified payable resource catalog.
    $projection=['duration_ms'=>(string)$duration,'lines'=>$lines];
    $value=ResourceManifest::maximum($projection);
    $usd=Amount::add(Amount::add($totals[$median],$egress),$backup);
    if($value['gru_units']!==$toGru($usd))throw new RuntimeException('GRU projection mismatch');
    $cap=Amount::mulDiv($value['gru_units'],100+$p['contingency_percent'],100,true);
    $own=Amount::mulDiv($cap,$p['dreambig_apps'],$p['apps'],true);
    $row=['required_millicores'=>$cpu,'required_ram_mib'=>$ram,'cloud_bundles'=>$bundles,'provisioned_vcpu'=>$bundles*2,'provisioned_ram_gib'=>$bundles*8,
        'provisioned_disk_gib'=>$bundles*128,'provider_compute_disk_usd_micros'=>$totals,'median_provider'=>$median,
        'common_egress_usd_micros'=>$egress,'common_backup_usd_micros'=>$backup,'reference_total_usd_micros'=>$usd,
        'whole_gru_before_contingency'=>$value['gru_units']/$p['gru_scale'],'whole_gru_budget'=>$cap/$p['gru_scale'],
        'dreambig_whole_gru_budget'=>$own/$p['gru_scale'],'clients_whole_gru_budget'=>($cap-$own)/$p['gru_scale'],
        'dreambig_90_day_whole_gru'=>3*$own/$p['gru_scale'],'pricing_projection'=>$projection,'gwd_budget_base_units'=>null];
    if($p['gwd_quote_base_units_per_whole_gru']!==null)$row['gwd_budget_base_units']=Amount::mulDiv($cap,Amount::integer($p['gwd_quote_base_units_per_whole_gru']),$p['gru_scale'],true);
    $out['scenarios'][$name]=$row;
}
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
