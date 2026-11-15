<?php
declare(strict_types=1);
use Greenwood\Economics\HostedServiceBinding;

// Included by independent-resource-settlement.php after native terminal settlement.
// Real HTTP handler, synthetic finalized tip: no transport or certificate claim.
$dir=sys_get_temp_dir().'/greenwood-archive-rpc-'.bin2hex(random_bytes(8));
$index=new \Greenwood\Persistence\StateSqlite($dir.'/state.sqlite');
try {
    $rpcChain=new \Greenwood\Node\Chain($f['g'],new \Greenwood\Persistence\BlockFilesystem($dir.'/blocks'),$index);
    $setView=static function($state)use($rpcChain,$chain,$f):void {
        $rpcChain->state=$state;
        $rpcChain->tip=new \Greenwood\Block\Block(new \Greenwood\Block\Header(1,$chain,44750,0,str_repeat('x',32),str_repeat('y',32),str_repeat('z',32),$state->getStateRoot(),addr(array_values($f['validators'])[0]),$f['g']->getGenesisHash(),0),[]);
    };
    $api=new \Greenwood\Rpc\HttpApi($rpcChain,fn()=>throw new RuntimeException('Read-only test'),fn()=>false,fn()=>0,fn()=>'validator',fn()=>true,fn()=>0,new \Greenwood\Node\ChainConfig());
    $get=static function(string $path)use($api):array {
        $response=$api->handle('GET',$path,'');
        check($response->status===200,'RPC succeeds: '.$path);
        return json_decode($response->body,true,512,JSON_THROW_ON_ERROR);
    };
    $path='/v1/economics/hosted/assignments/'.HostedServiceBinding::assignmentId($document);
    $setView($beforeArchive);
    $before=$get($path);
    check(array_column($before['records'],'id')===[$ids[1],$ids[0]],'Assignment history orders renewal before prior interval');
    $setView($s);
    $after=$get($path);
    $normalized=array_map(static function($row){unset($row['record_hash']);return $row;},$after['records']);
    check($normalized===$before['records'],'Archive v2 preserves assignment amounts, identities, order and financial statuses');
    check($after['chain_id']===$chain&&$after['genesis_hash']===bin2hex($genesis)&&$after['state_root']===bin2hex($s->getStateRoot())&&$after['finalized_hash']===bin2hex($rpcChain->tip->getHash())&&$after['finalized_height']===44750,'Assignment page pins exact chain and state');
    $first=$get($path.'?limit=1');
    $second=$get($path.'?limit=1&cursor='.$first['next_cursor']);
    check(array_merge($first['records'],$second['records'])===$after['records']&&$second['next_cursor']===null,'Archived assignment pagination has no loss or duplication');
    check($api->handle('GET',$path.'?cursor='.str_repeat('0',64),'')->status===400,'Unknown assignment cursor fails closed');
    check($api->handle('GET',$path.'?limit=0','')->status===400,'Zero page size is rejected');
    foreach($ids as $i=>$id){
        $detail=$get('/v1/economics/services/'.$id);
        check($detail['archive']===$s->economicSummary()['services']['archives'][$id]&&$detail['payment_status']===($i===0?'PAYMENT_FINALIZED':'REFUNDED')&&$detail['execution_status']==='UNVERIFIED'&&!$detail['provider_journal_settled_is_payment'],'Archived detail preserves financial truth without inventing runtime evidence');
        check($detail['state_root']===$after['state_root']&&$detail['finalized_hash']===$after['finalized_hash'],'Detail and assignment pins agree');
    }
    check($api->handle('GET','/v1/economics/hosted/funding/'.HostedServiceBinding::generation($document),'')->status===404,'Archived terminal interval cannot authorize runtime funding');
    $archives=$get('/v1/economics/archives?limit=1');
    $archivesNext=$get('/v1/economics/archives?limit=1&cursor='.$archives['next_cursor']);
    $listed=array_column(array_merge($archives['records'],$archivesNext['records']),'id');sort($listed);$expected=$ids;sort($expected);
    check($listed===$expected&&$archivesNext['next_cursor']===null,'Archive collection paginates both terminal records');
    check($get('/v1/economics/services')['records']===[],'Archived records leave live commitment collection');
    $legacy=clone $beforeArchive;
    resourceFinance($legacy,$provider,['action'=>'archive_v1','records'=>$archiveRows],44750,$chain);
    $setView($legacy);
    $legacyPage=$get($path);
    check($legacyPage['records']===[]&&str_contains($legacyPage['legacy_archive_lookup'],'UNVERIFIED'),'Legacy archive absence explicitly requires authenticated history');
    $legacyDetail=$get('/v1/economics/services/'.$ids[0]);
    check(array_keys($legacyDetail['archive'])===['id','program','status','payment','record_hash','archived_height']&&$legacyDetail['payment_status']==='PAYMENT_FINALIZED','Archive v1 keeps its original schema and direct financial lookup');
    $setView($beforeArchive);
    $mixed=clone $beforeArchive;
    resourceFinance($mixed,$provider,['action'=>'archive_v2','records'=>[$archiveRows[0]]],44750,$chain);
    $setView($mixed);
    $mixedPage=$get($path);
    check(array_column($mixedPage['records'],'id')===[$ids[1],$ids[0]],'Mixed live and archived history retains both intervals');
    $atomic=clone $beforeArchive;$originalRoot=$atomic->getStateRoot();
    $badRows=$archiveRows;$badRows[1]['record_hash']=str_repeat('0',64);
    rejects(fn()=>resourceFinance($atomic,$provider,['action'=>'archive_v2','records'=>$badRows],44750,$chain),'Invalid second digest rejects the entire archive batch');
    check($atomic->getStateRoot()===$originalRoot,'Failed archive batch cannot partially remove commitments or mutate nonce');
    rejects(fn()=>resourceFinance($atomic,$provider,['action'=>'archive_v2','records'=>[$archiveRows[0],$archiveRows[0]]],44750,$chain),'Duplicate archive record is rejected');
    check($atomic->getStateRoot()===$originalRoot,'Duplicate archive batch preserves the original state');
    $archivedRoot=$s->getStateRoot();
    rejects(fn()=>resourceFinance($s,$provider,['action'=>'archive_v2','records'=>$archiveRows],44750,$chain),'Archived record cannot be archived twice');
    check($s->getStateRoot()===$archivedRoot,'Archive replay cannot mutate replay protection or financial state');
} finally {
    $index->close();
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($dir);
}
