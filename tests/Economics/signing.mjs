import assert from 'node:assert/strict';
import {randomBytes} from 'node:crypto';
import {spawnSync} from 'node:child_process';
import {fileURLToPath,pathToFileURL} from 'node:url';
import path from 'node:path';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const sdk=process.env.GREENWOOD_SDK_DIR||path.resolve(root,'sdk/javascript');
const {createSigner,inspectPhysicalTransaction,encodeEconomicAction}=await import(pathToFileURL(path.join(sdk,'signing.mjs')));
const seed=randomBytes(32),signer=createSigner(seed),recipient='a'.repeat(64),chainId='greenwood-mainnet-economic-codec-test',genesis='0'.repeat(64);
const prepared=[signer.prepareTransfer({chainId,nonce:0,recipient,amount:'6000000000'}),signer.prepareTreasury({chainId,nonce:1,action:{reserve:'grants',action:'pause',genesis:'0'.repeat(64),policy_version:1}})];
prepared.push(signer.prepareService({chainId,genesis,nonce:2,action:{action:'refund',commitment:'b'.repeat(64)}}));
prepared.push(signer.prepareResourceCapability({chainId,genesis,nonce:3,capability:{version:2,genesis,provider_key:signer.publicKey,assets:[]}}));
const php=String.raw`require $argv[1];$p=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$t=Greenwood\Transaction\Envelope::decode(base64_decode($p['body']['payload'],true));$t->verify($p['chainId']);if(bin2hex($t->getHash())!==$p['txHash'])throw new Exception('hash mismatch');if($t->getType()===11){$r=Greenwood\Transaction\TransferU64::validate($t,$t->getSender(),0,1000000000000000,$p['chainId']);if($r['amount']!==6000000000)throw new Exception('amount truncation');}else Greenwood\Economics\CanonicalJson::decode($t->getPayload());echo 'OK';`;
let checks=0;
for(const p of prepared){const parsed=inspectPhysicalTransaction(p);assert.ok([11,12,13,14].includes(parsed.type));checks++;const result=spawnSync(process.env.PHP_BINARY||'php',['-r',php,path.join(root,'tests/Economics/bootstrap.php')],{input:JSON.stringify(p),encoding:'utf8',timeout:15000});assert.equal(result.status,0,result.stderr);assert.equal(result.stdout,'OK');checks+=2;}
const signingPhp=String.raw`require $argv[1];$p=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$pair=sodium_crypto_sign_seed_keypair(hex2bin($p['seed']));$key=new Greenwood\Crypto\Ed25519KeyPair(sodium_crypto_sign_publickey($pair),sodium_crypto_sign_secretkey($pair));$signed=Greenwood\Economics\ServiceAuthorization::approval($p['kind'],$p['document'],$key);if($signed!==$p['signed'])throw new Exception('Cross-language canonical signature mismatch');echo 'OK';`;
for(const kind of ['quote','usage','resource-usage','resource-source','resolution']){
 const document={chain:chainId,genesis,amount:'9223372036854775807',label:'日本語\u2028meter',nested:{'𐀀':1,'\ue000':2}};
 const signed=signer.signResourceDocument(kind,document);
 const result=spawnSync(process.env.PHP_BINARY||'php',['-r',signingPhp,path.join(root,'tests/Economics/bootstrap.php')],{input:JSON.stringify({seed:seed.toString('hex'),kind,document,signed}),encoding:'utf8',timeout:15000});assert.equal(result.status,0,result.stderr);assert.equal(result.stdout,'OK');checks+=2;
}
assert.throws(()=>signer.prepareService({chainId,genesis,nonce:1,action:{genesis:'1'.repeat(64)}}));checks++;
assert.throws(()=>signer.prepareResourceCapability({chainId,genesis,nonce:1,capability:{version:2,genesis,provider_key:'1'.repeat(64)}}));checks++;
assert.throws(()=>encodeEconomicAction({bad:'\ud800'}));checks++;
for(const amount of ['0','9223372036854775808','1e6',6000000000]){assert.throws(()=>signer.prepareTransfer({chainId,nonce:0,recipient,amount}));checks++;}
assert.throws(()=>encodeEconomicAction({amount:NaN}));checks++;assert.throws(()=>encodeEconomicAction([]));checks++;
assert.throws(()=>inspectPhysicalTransaction({...prepared[0],nonce:1}));checks++;
console.log(JSON.stringify({status:'PASS',checks,php_source_verification:true,live_transactions:0}));
