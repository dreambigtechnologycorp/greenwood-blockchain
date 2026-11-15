<?php
declare(strict_types=1);
require __DIR__.'/fixtures.php';
use Greenwood\Provider\{FinalizedHeadWatchdog,HostedServiceMonitor};
$now=100;$clock=static function()use(&$now):int{return $now;};$w=new FinalizedHeadWatchdog(180,$clock);$a=str_repeat('a',64);$b=str_repeat('b',64);
rejects(fn()=>$w->observe(10,$a),'Startup cannot authorize a possibly stale cached head');
$now=101;$w->observe(11,$b);check(true,'Observed progress arms the local watchdog');
$now=280;$w->observe(11,$b);check(true,'Fresh unchanged head remains within explicit local deadline');
$now=281;rejects(fn()=>$w->observe(11,$b),'Stalled head denies continuation at deadline');
rejects(fn()=>$w->observe(10,$a),'Rollback cannot reset the deadline');
rejects(fn()=>$w->observe(11,$a),'Same-height conflicting head denied');
$now=282;$w->observe(12,$a);check(true,'New finalized progress permits recovery');
$now=281;rejects(fn()=>$w->observe(12,$a),'Monotonic clock rollback fails closed');
$restart=new FinalizedHeadWatchdog(180,$clock);rejects(fn()=>$restart->observe(12,$a),'Process restart cannot reset freshness into authorization');
$events=[];$monitor=new HostedServiceMonitor(fn()=>[['state'=>'RUNNING','application_id'=>1,'service'=>'web','image_ref'=>'pinned']],fn()=>throw new RuntimeException('STALE'),static function()use(&$events){$events[]='restrict';throw new RuntimeException('edge unavailable');},static function()use(&$events){$events[]='stop';});
$result=$monitor->tick();check($events===['restrict','stop']&&$result[0]['outcome']==='RESTRICTION_RETRY_REQUIRED','Watchdog denial uses existing restriction-before-stop and retries uncertain failures');
echo json_encode(['status'=>'PASS','checks'=>$checks,'physical_cutoff_demonstrated'=>false],JSON_PRETTY_PRINT)."\n";
