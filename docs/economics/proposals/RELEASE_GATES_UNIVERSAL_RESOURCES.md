# Universal resource release gates — HOLD

**Superseded deployment scope:** the user's November 14 direction selects the
existing four-node cluster for replacement, without testnet state migration.
See `docs/operations/PRODUCTION_REBUILD.md` for the ordered source/publish/genesis/
installation/acceptance gates. Earlier exclusions of that cluster and requirements
for separate disposable hardware below are historical, not current instructions.
Source publication is authorized after review and applicable tests. Destructive
installation still requires exact-machine confirmation and explicit authorization.

Source completion is not production qualification. Remaining local source work:

- Complete governance-driven OCI distribution, staging/health/cutover/rollback and
  consent enforcement through existing supervisor/Ansible infrastructure.
- Implement reviewed persistent meter mounts and narrow collector transport; qualify
  device/volume/namespace attribution. Implement authoritative storage/network/vendor
  adapters where hardware exists and explicit unsupported results elsewhere.
- Wire coordinator/attestor workflow, outbox relay, operator commands and existing
  customer/operator UI. Exercise end-to-end trust-profile native funding journeys.
- Extend archival security/load tests, retained-history operational export, and
  capacity exhaustion/migration. Current bounded tombstones do not provide unlimited
  historical throughput. Treasury lost-quorum recovery remains a separate protocol.

Ratification required: both allocation candidates, fee/budget ceilings, domain and
beneficial-control reviewers/challenges, qualified measurement bases per resource,
E1/E2 thresholds/windows/evidence age/notice, bootstrap payable terms/subsidy rules,
service privileges/digests/schema/cost compensation, key custody/recovery, retention,
founder authority sunset, dynamic validator membership and activation procedure.

Physical requirements: designated disposable Linux/containerd host; real allocations,
counter resets and crash recovery; storage/device/network tenant attribution;
stale-head restriction and measured hard cutoff; real independent administrator/key
custody review; malicious telemetry/refusal/outage tests; distributed persistence,
certified replay and operational soak. Local multiple keys/processes do not qualify.

Inventories remain empty. The existing operating four-node cluster is excluded.
Do not contact it, reset it, install services, fund mainnet, create permanent genesis,
access protected signing keys, install dependencies, or push artifacts/source without
separate explicit authorization.
