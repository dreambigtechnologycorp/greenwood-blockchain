"""Independent wire/hash checks against frozen compute fixtures; no Greenwood imports."""
import hashlib
import json
from pathlib import Path
import re
import struct

base = Path(__file__).resolve().parent
fixture = (base / 'ComputeFixture.php').read_text()
def constant(name):
    return bytes.fromhex(re.search(r"public const " + name + r" = '([0-9a-f]+)'", fixture)[1])
def domain_hash(domain, body):
    tag = domain.encode('ascii')
    return hashlib.blake2b(struct.pack('>H', len(tag)) + tag + body, digest_size=32).digest()

v = json.loads((base / 'compute-v2.json').read_text())
v1 = constant('RECEIPT_UNSIGNED')
generation = struct.pack('>I', v['generation'])
expected = b'\x00\x02' + v1[2:34] + generation + v1[34:]
assert bytes.fromhex(v['unsigned_hex']) == expected
assert domain_hash('GREENWOOD_COMPUTE_RECEIPT_V2', expected).hex() == v['receipt_id']
assert domain_hash('GREENWOOD_RECEIPT_SIGN_V2', expected).hex() == v['signing_digest']
assert bytes.fromhex(v['signed_hex']) == expected + b'\x00\x40' + bytes.fromhex(v['signature'])
assert domain_hash('GREENWOOD_COMPUTE_RECEIPT_V1', v1) == constant('RECEIPT_ID')
assert domain_hash('GREENWOOD_COMPUTE_REQUEST_V1', constant('REQUEST_BYTES')) == constant('REQUEST_ID')
assert domain_hash('GREENWOOD_PROVIDER_V1', constant('PROVIDER_PUBLIC_KEY')) == constant('PROVIDER_ID')
assert bytes.fromhex(v['assignment_hex']) == (b'\x00\x01' + constant('REQUEST_ID')
    + constant('PROVIDER_ID') + constant('PROVIDER_PUBLIC_KEY') + generation + struct.pack('>Q', 123456))
print('Independent compute V1/V2 wire and domain-hash vectors: PASS')
