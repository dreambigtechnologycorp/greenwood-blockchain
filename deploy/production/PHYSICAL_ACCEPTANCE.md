# Physical acceptance on the existing four nodes

Run after exact-target/recovery preflight, finalized governance configuration and
explicit destructive-installation authorization. Do not execute on the disposable
old testnet as if it were the new production network. No additional cluster is
required. Store raw evidence privately and publish a redacted evidence manifest.

## 1. Establish the installation identity

Run the existing read-only preflight using the confirmed private inventory and
known host keys. Reconcile all machine IDs and recovery arrangements before reset.
After the authorized fresh install, validate the new genesis:

```sh
python3 scripts/production-preflight.py --inventory /PRIVATE/inventory.json \
  --known-hosts /PRIVATE/known_hosts --ssh-key /PRIVATE/ssh_identity \
  --output /PRIVATE/fresh-preflight.json
php scripts/economics-cli.php validate-production-genesis /PRIVATE/genesis.json
python3 scripts/production-acceptance.py /PRIVATE/release.json \
  --output /PRIVATE/consensus-first.json
```

The RPC script requires four distinct HTTPS origins, the exact new chain/genesis
and A/B/C public keys. It compares finalized blocks at a common height and rejects
wrong identities or mismatched state roots. Run again after blocks advance and
compare the common finalized height. These are authenticated node assertions;
separately replay exported certified history through the existing node/workflow
replay path and compare the exact state root. The gateway is not a fourth validator.

## 2. Qualify actual runtime and system services

On each rebuilt provider, run the pinned runtime verification program inside its
approved ARM64 runtime image and retain stdout and exit status. Verify the ELF
architecture, extension versions, Wasmtime library digest and dependencies against
the offline release manifest. Then run the native acceptance suite on the approved
installation using its configured containerd socket and digest-pinned test image:

```sh
php -d ffi.enable=true vendor/bin/phpunit --testsuite native \
  --log-junit /PRIVATE/native-acceptance.xml
```

Review the suite's explicit native environment requirements first; never substitute
a mutable image tag or silently skip infrastructure tests. Record actual container
IDs, OCI digest, cgroup identity, quotas, CPU/RAM/block/network observations and
cleanup. No missing GPU/NPU or storage source can count as qualified.

Through the existing governed-service installer, exercise install, health/readiness,
restart, update, drain, revoked digest denial and rollback on the installed meter,
attestor and coordinator. Record their signed installation audits. Explicitly test
an interrupted/uncertain start and prove it is inspected rather than started twice.

## 3. Verify registry and permissions

Verify Zot TLS hostname/chain, bearer realm and issuer public key independently.
Push/pull a digest-pinned acceptance artifact using an explicitly authorized
system-service publisher. Prove that app sessions, wrong system-service grants,
wrong genesis, expired policy, replayed requests and unapproved publishers fail.
Verify application publication still requires its own finalized registry rights.
Record manifest digests, never authentication tokens or private keys.

## 4. Execute funded finite and hosted journeys

Use newly provisioned acceptance customer/provider identities and an approved
funding amount/program. No helper bypasses treasury governance. Record the exact
accepted quote, resource limits, source/trust profile and prior-finalized escrow.
Run an actual containerd finite job and an actual hosted application through the
existing registry/controller/receiver path. Export closed, scoped meter evidence;
submit explicit provider/source/witness signatures through the existing workflows.

Record certified inclusion, challenge deadline, final provider payment, customer
refund, unused subsidy restoration and total GWD conservation. Exercise duplicate
claim/payment rejection, disputed usage, unclaimed expiry, witness loss, missing
sample, runtime restart, renewal and stale financial-head restriction. Observe the
actual workload/network cutoff; polling alone is not proof of an exact cutoff.

Epoch 0 may use approved operator-attested customer-paid services, with zero
independent-attestation subsidy authority. A single operator or several keys must
never be reported as independently qualified. Independent-mode outage must retain
its accepted verification requirements and bounded refund/dispute behavior.

## 5. Sign off the evidence

For every acceptance category in `release.template.json`, record a SHA-256 of the
reviewed evidence bundle and its actual command, hardware/model, source revision,
image digest, workload/commitment identity, expected/actual results and timestamps.
Run the release manifest preparer again. Missing categories, ratification, recovery
readiness or installation authorization stay explicit blockers. A successful RPC
check or local source suite cannot mark full physical acceptance complete.
