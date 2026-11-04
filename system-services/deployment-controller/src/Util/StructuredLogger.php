<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Util;

/** Accept only bounded audit categories and safe scalar fields, never exception text. */
final class StructuredLogger
{
    public function __construct(private readonly string $outputPath) {}
    public function log(string $level, string $event, array $context=[]): void
    {
        if (!in_array($level,['debug','info','warning','error'],true)
            || !preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D',$event)) throw new \InvalidArgumentException('Audit category required');
        $safe=[];
        foreach (['request_id','application_id','status','latency_ms','error_category'] as $key) {
            $value=$context[$key] ?? null;
            if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$value))) $safe[$key]=$value;
        }
        $row=['timestamp'=>time(),'level'=>$level,'event'=>$event,'context'=>$safe];
        if (file_put_contents($this->outputPath,json_encode($row,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX)===false) throw new \RuntimeException('Audit write failed');
    }
}
