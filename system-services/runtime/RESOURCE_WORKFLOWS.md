# Resource service release composition

`scripts/build-resource-services.sh NEW_OUTPUT_DIRECTORY` builds committed source
into local OCI archives for the meter, attestor and coordinator. Supply a reviewed
`GREENWOOD_PHP_RUNTIME=registry/image@sha256:…` with PHP 8.5, Sodium, SQLite, POSIX,
PCNTL and FFI plus the pinned Wasmtime library. The builder does not install or push
images. Record the OCI manifest digest from build metadata, not the tar SHA256, in
governance service policies. Publication and physical OCI acceptance remain separate.

The meter uses its existing private Unix protocol. The attestor and coordinator
use `resource-worker.php PRIVATE_DIRECTORY`, with an exclusive ownership lock and
`workflow.sock` mode 0600. Each canonical JSON line has `operation` and `request`.
Only the operation names below are accepted. Requests cannot select executable
paths, key paths, snapshots, node origins or shell commands. A child process handles
one request with bounded input/output and a ten-minute replay deadline. Disconnects
do not grant a retry a fresh transaction nonce or payment authorization.

The service directory must belong to UID 65532, mode 0700, mounted only through
reviewed `private_journal_rw` consent. Configuration is `config.json`, mode 0600.
Start from `resource-workflow.disabled.json`. An empty `allowed_operations` list
grants no action. Configure only the role's required operations:

- `prepare-local`: convert a closed, scoped meter bundle into unsigned CPU/RAM/block
  evidence. Request: `commitment`, `bundle`, and explicit `block_devices` asset-to-
  major:minor mapping. Unsupported resources and uncertain boundaries fail closed.
- `prepare-bootstrap`: verify explicit Epoch 0 operator approvals and prepare a
  customer-funded native claim. Request: `commitment`, `evidence`, `approvals`.
  Zero subsidy, explicit program/quote consent and finalized E0 roles are required.
- `prepare-independent`: the same request shape, but requires the accepted independent
  profile and current E2 qualification. There is no fallback to bootstrap.
- `attest-independent`: request `commitment`, `evidence`, `source_approval`. Uses only
  the explicitly provisioned private `witness.key`; current qualified-source and
  witness eligibility are checked before signing. Never auto-generates a key.
- `enqueue`: accept already-signed native transaction bytes as canonical base64
  `payload` into the genesis-pinned durable outbox. It does not broadcast.
- `relay`: reconcile and retry bounded outbox entries using the configured TLS or
  loopback `relay_origin`. A governed service has no host network by default; run
  this one-shot operation through the approved host transport when network access
  is required. Acknowledgement remains UNCERTAIN until certified inclusion.

Except for enqueue, operations replay an existing `blocks/` snapshot with validator
certificates and `genesis.json`. Configuration pins chain, genesis, exact finalized
height, block hash and state root. A derived private SQLite index is rebuildable.
Do not point this directory at a live node's mutable data directory. Publish a
coherent certified snapshot into the service's reviewed data area, then update its
pins explicitly. A snapshot is chain data, not a separate cluster or testnet.

The one-shot interface is also available as
`php system-services/runtime/resource-workflow.php PRIVATE_DIRECTORY OPERATION`.
It accepts one canonical request on stdin. Funding/claim transactions still require
the separately authorized payer/provider signing workflow. These services do not
hold treasury keys, allocate nonces, create genesis or approve spending policies.

RAM evidence is an operator allocation assertion checked against sampled hard
limits, not measured byte-milliseconds of consumption or independent availability.
Block I/O requires explicit device mapping and does not establish volume capacity,
durability, SSD/NVMe classification, or tenant independence. Other profiles stay
disabled until a corresponding reviewed collector and acceptance evidence exist.
