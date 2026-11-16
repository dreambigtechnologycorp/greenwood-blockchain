// Node signer: uses Greenwood physical-v1 wire format; does not broadcast transactions.
import {createPrivateKey,createPublicKey,sign,verify} from 'node:crypto';
import blake from 'blakejs';
import {hex32,frame} from './codec.mjs';
const be=(n,size)=>{
 const b=Buffer.alloc(size);
 if(size===8){if(typeof n!=='bigint'||n<0n||n>0x7fffffffffffffffn)throw new TypeError('u64 supported range');b.writeBigUInt64BE(n);}
 else {if(!Number.isSafeInteger(n)||n<0||n>=2**(size*8))throw new TypeError('Unsigned integer range');if(size===4)b.writeUInt32BE(n);else b.writeUInt16BE(n);}return b;
};
const bytes=b=>Buffer.concat([be(b.length,4),b]);
export function protocolHash(domain,payload){const d=Buffer.from(domain,'utf8');return Buffer.from(blake.blake2b(Buffer.concat([be(d.length,2),d,Buffer.from(payload)]),null,32));}
export function contractId(wasm){return protocolHash('GREENWOOD_CONTRACT_V1',wasm).toString('hex');}
// Shared canonical native-economics action format. Amounts must remain decimal strings.
export function encodeEconomicAction(action){
 const encode=(v,depth=0)=>{
  if(depth>24)throw new Error('Economic action depth');
  if(v===null||typeof v==='boolean')return JSON.stringify(v);
  if(typeof v==='string'){if(Buffer.from(v,'utf8').toString('utf8')!==v)throw new Error('Invalid Unicode');return JSON.stringify(v).replaceAll('\u2028','\\u2028').replaceAll('\u2029','\\u2029');}
  if(typeof v==='number'){if(!Number.isSafeInteger(v))throw new Error('Economic integers must be exact');return String(v);}
  if(Array.isArray(v)&&v.length>20000)throw new Error('Economic collection bound');
  if(Array.isArray(v))return '['+v.map(x=>encode(x,depth+1)).join(',')+']';
  if(typeof v==='object'&&Object.keys(v).length>20000)throw new Error('Economic collection bound');
  if(typeof v==='object'&&[Object.prototype,null].includes(Object.getPrototypeOf(v)))return '{'+Object.keys(v).sort((a,b)=>Buffer.compare(Buffer.from(a),Buffer.from(b))).map(k=>{if(/^-?(0|[1-9][0-9]*)$/.test(k))throw new Error('Numeric object keys are ambiguous in PHP');return encode(k,depth+1)+':'+encode(v[k],depth+1);}).join(',')+'}';
  throw new Error('Unsupported economic value');
 };
 if(!action||typeof action!=='object'||Array.isArray(action))throw new Error('Economic action object required');
 const bytes=Buffer.from(encode(action));if(bytes.length>65536)throw new Error('Economic action too large');return bytes;
}
export function createSigner(seed){
 if(!(seed instanceof Uint8Array)||seed.length!==32)throw new TypeError('32-byte Ed25519 seed required');
 const key=createPrivateKey({key:Buffer.concat([Buffer.from('302e020100300506032b657004220420','hex'),Buffer.from(seed)]),format:'der',type:'pkcs8'});
 const publicKey=createPublicKey(key).export({format:'der',type:'spki'}).subarray(-32);
 const account=protocolHash('GREENWOOD_ACCOUNT_V1',publicKey);
 const envelope=(chainId,nonce,type,payload)=>{
  if(typeof chainId!=='string'||chainId.includes('\0'))throw new TypeError('Chain ID required');const chain=Buffer.from(chainId,'utf8');
  if(chain.length<1||chain.length>128||!Number.isSafeInteger(nonce)||nonce<0||nonce>=0xffffffff)throw new TypeError('Chain or nonce range');
  const signing=Buffer.concat([Buffer.from([1]),be(chain.length,2),chain,account,publicKey,be(nonce,4),Buffer.from([type]),bytes(payload)]);
  const signature=sign(null,protocolHash('GREENWOOD_TX_SIGN_V1',signing),key);
  const canonical=Buffer.concat([signing,be(64,2),signature]);
  return {chainId,sender:account.toString('hex'),nonce,txHash:protocolHash('GREENWOOD_TX_V1',canonical).toString('hex'),body:{type:'transaction',payload:canonical.toString('base64')}};
 };
 return Object.freeze({accountId:account.toString('hex'),publicKey:publicKey.toString('hex'),
  prepareTransfer({chainId,nonce,recipient,amount}){
   hex32(recipient);if(typeof amount!=='bigint'&&(typeof amount!=='string'||!/^(0|[1-9][0-9]*)$/.test(amount)))throw new Error('Exact base-unit amount required');
   const value=BigInt(amount);if(value<1n||value>0x7fffffffffffffffn)throw new Error('Transfer amount range');
   return envelope(chainId,nonce,11,Buffer.concat([Buffer.from(recipient,'hex'),be(value,8)]));
  },
  prepareTreasury({chainId,nonce,action}){return envelope(chainId,nonce,12,encodeEconomicAction(action));},
  prepareService({chainId,genesis,nonce,action}){
   hex32(genesis);if(action.genesis!==undefined&&action.genesis!==genesis)throw new Error('Conflicting genesis');
   if(action.version!==undefined&&action.version!==1)throw new Error('Service action version');
   return envelope(chainId,nonce,13,encodeEconomicAction({...action,version:1,genesis}));
  },
  prepareResourceCapability({chainId,genesis,nonce,capability}){
   hex32(genesis);if(capability.genesis!==genesis||capability.version!==2||capability.provider_key!==publicKey.toString('hex'))throw new Error('Resource capability identity/version');
   return envelope(chainId,nonce,14,encodeEconomicAction(capability));
  },
  signResourceDocument(kind,document){
   const domain={'quote':'GREENWOOD_GRU_QUOTE_V1','usage':'GREENWOOD_USAGE_ATTEST_V1','resource-usage':'GREENWOOD_RESOURCE_USAGE_V1','resource-source':'GREENWOOD_QUALIFIED_RESOURCE_SOURCE_V1','resolution':'GREENWOOD_USAGE_RESOLVE_V1','telemetry':'GREENWOOD_RESOURCE_TELEMETRY_V1'}[kind];
   if(!domain)throw new Error('Unknown resource document domain');
   const body=encodeEconomicAction(document).toString('utf8');
   return {body,approval:{public_key:publicKey.toString('hex'),signature:sign(null,protocolHash(domain,Buffer.from(body)),key).toString('hex')}};
  },
  prepareCall({chainId,nonce,contractId:target,command,gasLimit=50000000}){
   const [method,data]=command;frame(method,data);hex32(target);
   if(!Number.isSafeInteger(gasLimit)||gasLimit<1||gasLimit>1000000000)throw new TypeError('Gas limit');
   const payload=Buffer.concat([Buffer.from(target,'hex'),bytes(Buffer.from(method)),bytes(Buffer.from(data)),be(0n,8),be(BigInt(gasLimit),8)]);
   return {...envelope(chainId,nonce,3,payload),contractId:target,method};
  },
  prepareDeployment({chainId,nonce,wasm,gasLimit=50000000}){
   if(!(wasm instanceof Uint8Array)||wasm.length<8||wasm.length>1048576||Buffer.from(wasm).subarray(0,8).toString('hex')!=='0061736d01000000')throw new TypeError('Invalid WASM bytes');
   if(!Number.isSafeInteger(gasLimit)||gasLimit<1||gasLimit>1000000000)throw new TypeError('Gas limit');
   const payload=Buffer.concat([bytes(Buffer.from(wasm)),bytes(Buffer.alloc(0)),be(0n,8),be(BigInt(gasLimit),8)]);
   return {...envelope(chainId,nonce,2,payload),contractId:contractId(wasm)};
  }
 });
}

/** Verify a saved call envelope before using it for reconciliation. */
export function inspectCallTransaction(prepared){
 if(!prepared||prepared.body?.type!=='transaction'||typeof prepared.body.payload!=='string'||prepared.body.payload.length>8192)throw new Error('Invalid saved transaction');
 const raw=Buffer.from(prepared.body.payload,'base64');if(raw.toString('base64')!==prepared.body.payload)throw new Error('Noncanonical transaction encoding');
 let pos=0;const take=n=>{if(n<0||pos+n>raw.length)throw new Error('Truncated transaction');const b=raw.subarray(pos,pos+n);pos+=n;return b;};
 if(take(1)[0]!==1)throw new Error('Envelope version');const chainSize=take(2).readUInt16BE();if(chainSize<1||chainSize>128)throw new Error('Chain length');
 const chainId=new TextDecoder('utf-8',{fatal:true}).decode(take(chainSize)),sender=take(32),publicKey=take(32),nonce=take(4).readUInt32BE();
 if(take(1)[0]!==3)throw new Error('Only contract calls can be reconciled here');
 const payload=take(take(4).readUInt32BE());const signedEnd=pos;
 if(take(2).readUInt16BE()!==64)throw new Error('Signature length');const signature=take(64);if(pos!==raw.length)throw new Error('Trailing transaction bytes');
 const pub=createPublicKey({key:Buffer.concat([Buffer.from('302a300506032b6570032100','hex'),publicKey]),format:'der',type:'spki'});
 if(!protocolHash('GREENWOOD_ACCOUNT_V1',publicKey).equals(sender)||!verify(null,protocolHash('GREENWOOD_TX_SIGN_V1',raw.subarray(0,signedEnd)),pub,signature))throw new Error('Invalid saved transaction signature');
 let at=0;const part=n=>{if(n<0||at+n>payload.length)throw new Error('Truncated call');const b=payload.subarray(at,at+n);at+=n;return b;};
 const target=part(32).toString('hex'),method=new TextDecoder('utf-8',{fatal:true}).decode(part(part(4).readUInt32BE())),data=part(part(4).readUInt32BE());
 const value=part(8).readBigUInt64BE(),gas=part(8).readBigUInt64BE();if(at!==payload.length||value!==0n||gas<1n||gas>1000000000n)throw new Error('Invalid call value/gas');frame(method,data);
 const txHash=protocolHash('GREENWOOD_TX_V1',raw).toString('hex');
 if(prepared.chainId!==chainId||prepared.sender!==sender.toString('hex')||prepared.nonce!==nonce||prepared.txHash!==txHash||prepared.contractId!==target||prepared.method!==method)throw new Error('Saved transaction metadata mismatch');
 return {chainId,sender:sender.toString('hex'),nonce,txHash,contractId:target,method,data};
}

/** Physical-v1 envelope verification for the shared InTown outbox, including transfers/deploys. */
export function inspectPhysicalTransaction(p){
 if(!p||p.body?.type!=='transaction'||typeof p.body.payload!=='string'||p.body.payload.length>3000000)throw new Error('Invalid physical transaction');
 const raw=Buffer.from(p.body.payload,'base64');if(raw.toString('base64')!==p.body.payload)throw new Error('Noncanonical envelope');
 let at=0;const take=n=>{if(n<0||at+n>raw.length)throw new Error('Truncated envelope');const b=raw.subarray(at,at+n);at+=n;return b;};
 if(take(1)[0]!==1)throw new Error('Envelope version');const length=take(2).readUInt16BE();if(length<1||length>128)throw new Error('Chain length');
 const chainId=new TextDecoder('utf-8',{fatal:true}).decode(take(length));if(chainId.includes('\0'))throw new Error('Chain ID');
 const sender=take(32),publicKey=take(32),nonce=take(4).readUInt32BE(),type=take(1)[0],payload=take(take(4).readUInt32BE()),signedEnd=at;
 if(take(2).readUInt16BE()!==64)throw new Error('Signature length');const signature=take(64);
 const pub=createPublicKey({key:Buffer.concat([Buffer.from('302a300506032b6570032100','hex'),publicKey]),format:'der',type:'spki'});
 if(at!==raw.length||!protocolHash('GREENWOOD_ACCOUNT_V1',publicKey).equals(sender)||!verify(null,protocolHash('GREENWOOD_TX_SIGN_V1',raw.subarray(0,signedEnd)),pub,signature))throw new Error('Invalid physical signature');
 let offset=0;const part=n=>{if(n<0||offset+n>payload.length)throw new Error('Truncated payload');const b=payload.subarray(offset,offset+n);offset+=n;return b;};const field=()=>part(part(4).readUInt32BE());
 let target,method;
 if(type===1){part(32);part(4);}
 else if(type===11){part(32);const amount=part(8).readBigUInt64BE();if(amount<1n||amount>0x7fffffffffffffffn)throw new Error('U64 amount range');}
 else if([12,13,14].includes(type)){const rawAction=part(payload.length);const action=JSON.parse(new TextDecoder('utf-8',{fatal:true}).decode(rawAction));if(!encodeEconomicAction(action).equals(rawAction))throw new Error('Noncanonical native action');if(type!==12){hex32(action.genesis);if(action.version!==(type===13?1:2))throw new Error('Native action version');if(type===14&&action.provider_key!==publicKey.toString('hex'))throw new Error('Resource provider key');}}
 else if(type===2){const wasm=field();if(wasm.length<8||wasm.length>1048576||wasm.subarray(0,8).toString('hex')!=='0061736d01000000')throw new Error('WASM bounds');target=contractId(wasm);field();part(8);part(8);}
 else if(type===3){target=part(32).toString('hex');method=new TextDecoder('utf-8',{fatal:true}).decode(field());if(!/^[A-Za-z_][A-Za-z0-9_]{0,63}$/.test(method))throw new Error('Method');field();part(8);part(8);}
 else throw new Error('Unsupported physical transaction type');
 const txHash=protocolHash('GREENWOOD_TX_V1',raw).toString('hex');
 if(offset!==payload.length||p.chainId!==chainId||p.sender!==sender.toString('hex')||p.nonce!==nonce||p.txHash!==txHash||p.contractId!==target||p.method!==method)throw new Error('Physical metadata mismatch');
 return {chainId,sender:sender.toString('hex'),nonce,txHash,type,contractId:target,method};
}
