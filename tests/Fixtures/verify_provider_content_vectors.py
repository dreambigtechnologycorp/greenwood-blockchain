"""Independent verification of the frozen provider commitments; no PHP imports."""
import hashlib
import json
import pathlib
import struct

vectors = json.loads(pathlib.Path(__file__).with_name("provider-content-v1.json").read_text())
sha = lambda data: hashlib.sha256(data).digest()
assert bytes.fromhex(vectors["oci_digest"].removeprefix("sha256:")).hex() == vectors["workload_digest"]
for item in vectors["inputs"]:
    assert sha(bytes.fromhex(item["hex"])).hex() == item["digest"]
for item in vectors["outputs"]:
    preimage = b""
    for stream in ("stdout", "stderr"):
        data = bytes.fromhex(item[stream + "_hex"])
        assert len(data) == item[stream + "_length"]
        assert sha(data).hex() == item[stream + "_hash"]
        assert data[:item["retention_limit"]].hex() == item["retained_" + stream + "_hex"]
        assert (len(data) > item["retention_limit"]) == item[stream + "_truncated"]
        preimage += struct.pack(">Q", len(data)) + sha(data)
    assert len(preimage) == 80
    assert sha(preimage).hex() == item["digest"]
domain = b"GREENWOOD_EXECUTION_V1"
assert hashlib.blake2b(struct.pack(">H", len(domain)) + domain + vectors["runtime_execution_id"].encode("ascii"), digest_size=32).hexdigest() == vectors["execution_id"]
print(f"Verified {len(vectors['outputs'])} output vectors and input/workload/execution identities")
