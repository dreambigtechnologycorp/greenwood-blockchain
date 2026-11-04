"""Independent standard-library verification of binary hashes and state commitments."""
import hashlib, json, pathlib, struct
v=json.loads((pathlib.Path(__file__).parent/'protocol-v1.json').read_text())
def h(d,b):
    d=d.encode(); return hashlib.blake2b(struct.pack('>H',len(d))+d+b,digest_size=32).digest()
def raw(k): return bytes.fromhex(v[k])
for output,domain,source in [('address','GREENWOOD_ACCOUNT_V1','sender_public_key'),('genesis_hash','GREENWOOD_GENESIS_V1','genesis_encoding'),('transaction_digest','GREENWOOD_TX_SIGN_V1','transaction_unsigned'),('transaction_hash','GREENWOOD_TX_V1','transaction_encoding'),('block_hash','GREENWOOD_BLOCK_V1','block_header'),('proposal_digest','GREENWOOD_PROPOSAL_V1','proposal_unsigned'),('prevote_digest','GREENWOOD_PREVOTE_V1','prevote_unsigned'),('precommit_digest','GREENWOOD_PRECOMMIT_V1','precommit_unsigned'),('contract_id','GREENWOOD_CONTRACT_V1','wasm')]:
    assert h(domain,raw(source))==raw(output),output
# Independently parse the genesis and construct state leaf preimages.
g=raw('genesis_encoding'); o=12
n=int.from_bytes(g[o:o+2],'big'); o+=2+n
n=int.from_bytes(g[o:o+4],'big'); o+=4
validators=[]
for _ in range(n):
    n=g[o]; o+=1; label=g[o:o+n]; o+=n; key=g[o:o+32]; o+=32; power=g[o:o+4]; o+=4
    validators.append((b'validators',label,key+power))
def b(x): return struct.pack('>I',len(x))+x
def root(records):
    level=[h('GREENWOOD_STATE_LEAF_V1',b(ns)+b(key)+b(value)) for ns,key,value in sorted(records)]
    while len(level)>1:
        if len(level)%2: level.append(level[-1])
        level=[h('GREENWOOD_STATE_NODE_V1',level[i]+level[i+1]) for i in range(0,len(level),2)]
    return level[0]
assert root(validators+[(b'accounts',raw('address'),struct.pack('>QI',1000000,0))])==raw('initial_state_root')
recipient=h('GREENWOOD_ACCOUNT_V1',raw('recipient_public_key'))
assert root(validators+[(b'accounts',raw('address'),struct.pack('>QI',987655,1)),(b'accounts',recipient,struct.pack('>QI',12345,0))])==raw('state_root')
print('Independent Python hash/state-root golden vectors passed')
