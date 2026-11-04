"""Independent canonical codec, hashes and unified state-root verification.
No imports of PHP or Greenwood implementation code; vectors are never rewritten.
"""
import hashlib,json,struct
from pathlib import Path
v=json.loads((Path(__file__).parent/'contracts-v1.json').read_text())
def u32(x):return struct.pack('>I',x)
def u64(x):return struct.pack('>Q',x)
def short(x):return struct.pack('>H',len(x))+x
def blob(x):return u32(len(x))+x
def h(domain,b):return hashlib.blake2b(short(domain.encode())+b,digest_size=32).digest()
def root(records):
 records=sorted(records,key=lambda r:(r['namespace'].encode(),bytes.fromhex(r['key'])))
 nodes=[h('GREENWOOD_STATE_LEAF_V1',blob(r['namespace'].encode())+blob(bytes.fromhex(r['key']))+blob(bytes.fromhex(r['value']))) for r in records]
 if not nodes:return h('GREENWOOD_STATE_EMPTY_V1',b'')
 while len(nodes)>1:
  if len(nodes)%2:nodes.append(nodes[-1])
  nodes=[h('GREENWOOD_STATE_NODE_V1',nodes[i]+nodes[i+1]) for i in range(0,len(nodes),2)]
 return nodes[0]
sender=bytes.fromhex(v['sender']);pk=bytes.fromhex(v['sender_public_key'])
assert h('GREENWOOD_ACCOUNT_V1',pk)==sender
previous=None
for c in v['cases']:
 wasm=bytes.fromhex(c['wasm']);cid=h('GREENWOOD_CONTRACT_V1',wasm); data=bytes.fromhex(c['calldata'])
 assert cid.hex()==c['contract_id']==c['wasm_hash']
 if c['type']=='deploy':payload=blob(wasm)+blob(data)+u64(c['attached_value'])+u64(c['gas_limit']);kind=2
 else:payload=cid+blob(c['method'].encode())+blob(data)+u64(c['attached_value'])+u64(c['gas_limit']);kind=3
 assert payload.hex()==c['payload']
 nonce=next(struct.unpack('>QI',bytes.fromhex(r['value']))[1] for r in c['pre_state'] if r['namespace']=='accounts' and bytes.fromhex(r['key'])==sender)
 unsigned=b'\x01'+short(v['chain_id'].encode())+sender+pk+u32(nonce)+bytes([kind])+blob(payload)
 assert unsigned.hex()==c['unsigned_transaction']
 signed=bytes.fromhex(c['transaction']);assert signed[:-66]==unsigned and signed[-66:-64]==b'\x00\x40'
 assert h('GREENWOOD_TX_SIGN_V1',unsigned).hex()==c['signing_digest']
 assert h('GREENWOOD_TX_V1',signed).hex()==c['transaction_hash']
 assert root(c['pre_state']).hex()==c['pre_root'] and root(c['post_state']).hex()==c['post_root']
 if previous is not None: assert previous==c['pre_state']
 previous=c['post_state']
 receipt=c['receipt']; assert receipt['contract_id']==cid.hex() and receipt['transaction_hash']==c['transaction_hash']
 assert 0<=receipt['gas_used']<=c['gas_limit']
 if receipt['status']!='SUCCESS':
  assert c['pre_state']==c['post_state'] and receipt['events']==[] and receipt['return_data']==''
 else:
  for i,e in enumerate(receipt['events']):assert e['event_index']==i and e['transaction_hash']==c['transaction_hash'] and e['contract_id']==cid.hex()
  record=next(r for r in c['post_state'] if r['namespace']=='contract_code' and r['key']==cid.hex())
  assert bytes.fromhex(record['value'])==u32(1)+cid
print(f"Independent contract payload/hash/state-root vectors passed ({len(v['cases'])} executions)")
