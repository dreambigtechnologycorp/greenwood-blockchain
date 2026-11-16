import { createPublicKey, verify } from 'node:crypto';
import { connect } from 'node:net';
import { lstat, realpath } from 'node:fs/promises';
import { dirname, basename } from 'node:path';
import { encodeEconomicAction, protocolHash } from './signing.mjs';
import { hex32 } from './codec.mjs';
export { createSigner, inspectPhysicalTransaction, encodeEconomicAction } from './signing.mjs';
const integer = x => { if(typeof x !== 'string' || !/^(0|[1-9][0-9]*)$/.test(x) || BigInt(x)>9223372036854775807n)throw Error('Exact decimal base units required');return BigInt(x); };
export const displayGwd = units => { const n=integer(units);return `${n/1000000n}.${(n%1000000n).toString().padStart(6,'0')}`; };
export function programPolicyHash(program) { return protocolHash('GREENWOOD_SERVICE_PROGRAM_POLICY_V1',encodeEconomicAction(program)).toString('hex'); }
/** An offer disclosure, not an RPC assertion of finality or permission to debit funds. */
export function inspectResourceOffer({body,approval,program,chainId,genesis,payer,finalizedHeight}) {
  hex32(genesis);hex32(payer);hex32(program.provider_key);
  if(!Number.isSafeInteger(finalizedHeight)||finalizedHeight<0)throw Error('Pinned finalized height required');
  const q=JSON.parse(body);if(encodeEconomicAction(q).toString()!==body)throw Error('Noncanonical offer');
  const publicKey=createPublicKey({key:Buffer.concat([Buffer.from('302a300506032b6570032100','hex'),Buffer.from(program.provider_key,'hex')]),format:'der',type:'spki'});
  if(approval?.public_key!==program.provider_key||typeof approval.signature!=='string'||!/^[a-f0-9]{128}$/.test(approval.signature)||!verify(null,protocolHash('GREENWOOD_GRU_QUOTE_V1',Buffer.from(body)),publicKey,Buffer.from(approval.signature,'hex')))throw Error('Provider quote signature mismatch');
  if(q.chain!==chainId||q.genesis!==genesis||q.payer!==payer||q.service_id!==program.service_id||q.catalog_hash!==program.catalog_hash||q.provider!==protocolHash('GREENWOOD_ACCOUNT_V1',Buffer.from(program.provider_key,'hex')).toString('hex'))throw Error('Offer does not match accepted chain, customer and program');
  if(!Number.isSafeInteger(q.expiry_height)||!Number.isSafeInteger(q.issued_height)||q.issued_height>finalizedHeight||q.expiry_height<=finalizedHeight)throw Error('Offer not currently valid');
  const hash=programPolicyHash(program);
  if(!['BOOTSTRAP_OPERATOR_ATTESTED','INDEPENDENT_ATTESTED'].includes(program.trust_profile)||q.trust_profile!==program.trust_profile||q.program_policy_hash!==hash)throw Error('Exact disclosed trust policy required');
  if(!Number.isSafeInteger(program.subsidy_percent)||program.subsidy_percent<0||program.subsidy_percent>100)throw Error('Invalid subsidy percentage');
  if(program.trust_profile==='BOOTSTRAP_OPERATOR_ATTESTED'&&program.subsidy_percent!==0)throw Error('Bootstrap subsidies forbidden');
  integer(q.max_gru_units);integer(q.max_gwd_units);
  if(q.resource_quote_version!==2||!q.resource_manifest||q.resource_manifest_hash!==protocolHash('GREENWOOD_RESOURCE_MANIFEST_V1',encodeEconomicAction(q.resource_manifest)).toString('hex'))throw Error('Resource offer manifest mismatch');
  return Object.freeze({programPolicyHash:hash,providerKey:program.provider_key,trustProfile:q.trust_profile,
    independentVerificationRequired:q.trust_profile==='INDEPENDENT_ATTESTED',maxProviderGwd:displayGwd(q.max_gwd_units),maxGruUnits:q.max_gru_units,
    subsidyPercent:program.subsidy_percent,expiryHeight:q.expiry_height,resourceManifest:q.resource_manifest,
    refundPolicy:Object.freeze({unattested_after_end_blocks:4320,dispute_refund_after_blocks:43200,challenge_blocks:4320}),
    transactionFeesIncluded:false,nativeValidationRequired:true});
}
export function prepareFunding({signer,offer,approval,program,chainId,genesis,nonce,finalizedHeight,binding,endHeight,acceptedPolicyHash}) {
  const disclosure=inspectResourceOffer({body:offer,approval,program,chainId,genesis,payer:signer.accountId,finalizedHeight});
  if(acceptedPolicyHash!==disclosure.programPolicyHash)throw Error('Explicit acceptance of this exact policy required');
  if(!Number.isSafeInteger(endHeight)||endHeight<=finalizedHeight)throw Error('Future bounded service interval required');
  if(!binding||typeof binding!=='object'||Array.isArray(binding))throw Error('Explicit hosted or compute binding required');
  const keys=Object.keys(binding).sort().join(',');
  if(keys!=='hosted'&&(keys!=='generation,request_id'||!Number.isSafeInteger(binding.generation)||binding.generation<0))throw Error('Exact hosted or versioned compute binding required');
  return signer.prepareService({chainId,genesis,nonce,action:{action:'fund',program:program.id,...binding,end_height:endHeight,quote:offer,quote_approvals:[approval]}});
}
export function recoveryAction(action,genesis,policyVersion,fields) {
  hex32(genesis);if(!Number.isSafeInteger(policyVersion)||policyVersion<1)throw Error('Current policy version required');
  const allowed=action==='recovery_propose'?['nonce','signers','guardians','reason_hash']:['proposal'];
  if(!['recovery_propose','recovery_approve','recovery_veto','recovery_execute'].includes(action)||!fields||Object.keys(fields).some(k=>!allowed.includes(k))||Object.keys(fields).length!==allowed.length)throw Error('Exact recovery fields required; no financial-policy changes');
  return {action,genesis,policy_version:policyVersion,...fields};
}
/** Only private local IPC, with bounded request/response and no automatic signing. */
export async function meterRequest(socketPath,command,timeoutMs=5000) {
  if(basename(socketPath)!=='meter.sock'||!Number.isSafeInteger(timeoutMs)||timeoutMs<1||timeoutMs>60000)throw Error('Private meter socket and bounded timeout required');
  const [dir,socket]=await Promise.all([lstat(dirname(socketPath)),lstat(socketPath)]);
  if(await realpath(dirname(socketPath))!==dirname(socketPath)||dir.uid!==process.getuid()||socket.uid!==process.getuid())throw Error('Owned canonical IPC path required');
  if(!dir.isDirectory()||dir.isSymbolicLink()||!socket.isSocket()||socket.isSymbolicLink()||(dir.mode&0o077)||(socket.mode&0o077))throw Error('Private IPC permissions required');
  const raw=Buffer.concat([encodeEconomicAction(command),Buffer.from('\n')]);if(raw.length>65536)throw Error('Meter frame exceeds limit');
  return new Promise((resolve,reject)=>{const peer=connect(socketPath);let buffer=Buffer.alloc(0),done=false;
    const finish=(err,result)=>{if(done)return;done=true;peer.destroy();err?reject(err):resolve(result);};
    peer.setTimeout(timeoutMs,()=>finish(Error('Meter timeout')));peer.on('error',e=>finish(e));peer.on('connect',()=>peer.write(raw));
    peer.on('data',chunk=>{buffer=Buffer.concat([buffer,chunk]);if(buffer.length>65536)return finish(Error('Meter response exceeds limit'));const end=buffer.indexOf(10);if(end<0)return;
      try{const result=JSON.parse(buffer.subarray(0,end).toString());if(result.status!=='OK')throw Error('Meter reports UNVERIFIED');finish(null,result.result);}catch(e){finish(e);}});
    peer.on('end',()=>{if(!done)finish(Error('Incomplete meter response'));});
  });
}
