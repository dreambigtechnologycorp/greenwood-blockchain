<?php
declare(strict_types=1);
// Disposable in-memory identities only. Never receives production vault paths.
require __DIR__.'/../../scripts/operator-key-vault.php';
require __DIR__.'/../../src/Crypto/Ed25519KeyPair.php';
umask(0077);
function check(bool $ok): void { if (!$ok) throw new RuntimeException('Fixture assertion failed'); }
function denied(callable $fn): void { try { $fn(); } catch (Throwable) { return; } throw new RuntimeException('Expected rejection'); }
$plan=['version'=>1,'entries'=>[
 ['name'=>'fixture-node','format'=>'greenwood-ed25519'],
 ['name'=>'fixture-issuer','format'=>'sodium-ed25519'],
 ['name'=>'fixture-token','format'=>'token'],
 ['name'=>'fixture-tls','format'=>'tls-rsa','dns_name'=>'fixture.example.org']]];
$p=OperatorKeyVault::generate($plan);
$node=base64_decode($p['private']['fixture-node']['base64']);
$key=Greenwood\Crypto\Ed25519KeyPair::fromBytes($node);
check(strlen($node)===96 && sodium_crypto_sign_verify_detached($key->sign('fixture'),'fixture',$key->getPublicKey()));
check(bin2hex($key->getPublicKey())===$p['public']['fixture-node']['public_key_hex']);
$issuer=base64_decode($p['private']['fixture-issuer']['base64']);
check(strlen($issuer)===96 && bin2hex(sodium_crypto_sign_publickey($issuer))===$p['public']['fixture-issuer']['public_key_hex']);
check(sodium_crypto_sign_verify_detached(sodium_crypto_sign_detached('fixture',sodium_crypto_sign_secretkey($issuer)),'fixture',sodium_crypto_sign_publickey($issuer)));
check($p['public']['fixture-node']['public_key_hex']!==$p['public']['fixture-issuer']['public_key_hex']);
check(preg_match('/^[a-f0-9]{64}\n$/D',base64_decode($p['private']['fixture-token']['base64']))===1);
$tls=openssl_pkey_get_private(base64_decode($p['private']['fixture-tls']['base64']));
check(openssl_pkey_get_details($tls)['key']===$p['public']['fixture-tls']['public_key_pem']);
check(openssl_pkey_get_details(openssl_csr_get_public_key($p['public']['fixture-tls']['csr_pem']))['key']===$p['public']['fixture-tls']['public_key_pem']);
$password='disposable fixture password only';$blob=OperatorKeyVault::seal($p,$password);
check(OperatorKeyVault::open($blob,$password)===$p);
denied(fn()=>OperatorKeyVault::open($blob,'wrong fixture password'));
$bad=$blob;$bad[strlen($bad)-1]=chr(ord($bad[strlen($bad)-1])^1);denied(fn()=>OperatorKeyVault::open($bad,$password));
check(!str_contains($blob,$node) && !str_contains(json_encode($p['public']),base64_encode($node)));
denied(fn()=>OperatorKeyVault::plan(['version'=>1,'entries'=>[$plan['entries'][0],$plan['entries'][0]]]));
denied(fn()=>OperatorKeyVault::plan(['version'=>1,'entries'=>[['name'=>'../escape','format'=>'token']]]));
$dir=sys_get_temp_dir().'/greenwood-vault-fixture-'.bin2hex(random_bytes(8));mkdir($dir,0700);
try {
 OperatorKeyVault::writeNew($dir.'/fixture.gwkv',$blob);check((fileperms($dir.'/fixture.gwkv')&0777)===0600);
 check(OperatorKeyVault::readPrivate($dir.'/fixture.gwkv')===$blob);
 denied(fn()=>OperatorKeyVault::writeNew($dir.'/fixture.gwkv','overwrite'));
 symlink($dir.'/fixture.gwkv',$dir.'/link');denied(fn()=>OperatorKeyVault::readPrivate($dir.'/link'));
 denied(fn()=>OperatorKeyVault::writeNew($dir.'/link','overwrite'));
 link($dir.'/fixture.gwkv',$dir.'/hard');denied(fn()=>OperatorKeyVault::readPrivate($dir.'/fixture.gwkv'));unlink($dir.'/hard');
 chmod($dir.'/fixture.gwkv',0644);denied(fn()=>OperatorKeyVault::readPrivate($dir.'/fixture.gwkv'));
} finally { foreach (['link','hard','fixture.gwkv'] as $f) if (file_exists($dir.'/'.$f)||is_link($dir.'/'.$f)) unlink($dir.'/'.$f);rmdir($dir); }
echo "PASS: disposable formats, signatures, CSR, encryption, authentication and filesystem guards\n";
