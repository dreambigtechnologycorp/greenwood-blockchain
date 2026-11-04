<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Util;

/** Probes are read-only and return true only after verifying a live dependency. */
final class DependencyReadiness
{
    public function __construct(private readonly array $probes) {}
    public function inspect(): array {
        $checks=[];
        foreach (['finalized_authority','operation_store','provider','observer','edge'] as $name) {
            try { $probe=$this->probes[$name] ?? null; $checks[$name]=$probe instanceof \Closure && $probe()===true; }
            catch (\Throwable) { $checks[$name]=false; }
        }
        return ['ready'=>!in_array(false,$checks,true),'checks'=>$checks];
    }
}
