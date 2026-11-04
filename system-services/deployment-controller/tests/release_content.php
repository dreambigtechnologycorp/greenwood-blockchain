<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Greenwood\Edge\ReleaseContentVerifier as V;
function reject(Closure $f):void {try{$f();}catch(Throwable){return;}throw new RuntimeException('Expected rejection');}
$body='<html>Marketing /assets/main.js'.'{i:"__root__' . "\0" . '",u:1792370532380,s:"success",ssr:!0}'.'{i:"' . "\0\0" . '",u:1792370532380,s:"success",ssr:!0}</html>';
$mode='tanstack-root-index-v1';
$e=['body_verifier'=>$mode,'body_sha256'=>V::digest($body,$mode),'assets'=>['/assets/main.js'=>hash('sha256','asset')]];
$fetch=static fn()=>[200,'asset'];
if(V::verify(str_replace('1792370532380','1792370532999',$body),$e,$fetch)!==$e['body_sha256'])throw new RuntimeException('Timestamp normalization failed');
reject(fn()=>V::verify(str_replace('Marketing','Placeholder',$body),$e,$fetch));
reject(fn()=>V::verify($body,$e,static fn()=>[200,'tampered']));
reject(fn()=>V::verify(str_replace('1792370532380','invalid',$body),$e,$fetch));
reject(fn()=>V::verify($body.$body,$e,$fetch));
reject(fn()=>V::verify($body,$e+[],static fn()=>[302,'asset']));
reject(fn()=>V::digest($body,'unknown'));
if(V::digest($body)!==hash('sha256',$body))throw new RuntimeException('Raw hash regression');
echo "Release content: timestamp-only variation, HTML/asset tampering, shape, redirect and raw compatibility PASS\n";
