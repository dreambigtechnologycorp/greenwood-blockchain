# Connect resource sources to the existing provider

Start with [role-based onboarding](../operations/ROLE_BASED_ONBOARDING.md) for
service placement and [project status](../PROJECT_STATUS.md) for release scope.
These interfaces describe source composition, not an automatic host installer
or an approval to enable resource claims on another network.

The collector boundary is operator-controlled. These interfaces extend the
existing provider, funding guard and settlement workflow; they introduce no new
currency, public storage API or payment authority. The same source works for local
qualification and later production configuration. Defaults remain disabled.

## Admission and allocation

Use one `ResourceAllocationJournal` directory for **all** workloads competing for
one host's capacity. Its inventory contains `asset_id`, `resource_id`, `capacity`,
`exclusive`, `identity_hash` and `profile_hash`. Each asset has exactly one reserved
resource and one reviewed physical identity. Units are the catalog capacity units.
The inventory is immutable after creation; changing it requires an explicit
capacity migration, not replacing a JSON file during active allocations.

After the existing guard verifies finalized funding and assignment, call:

```php
$allocations->reserve($scope, $manifest, $runtimeIdentity, $bootHash, $startMs, $endMs);
```

The operation serializes admission with SQLite `BEGIN IMMEDIATE`, checks overlapping
intervals and capacity, and rejects exclusive device reuse. Use the same monotonic
clock and boot identity as the collector. A restart preserves leases; a reboot
requires explicit reconciliation. Call `release(commitment, runtimeIdentity)` only
after observing device/runtime detachment. Released commitments remain tombstoned.
The journal is an admission guard, not a hardware provisioning tool: apply the
existing containerd limits and reviewed device assignment before declaring READY.

## Host and accelerator observations

`ResourceHostCollector(namespace, containerId, scope, runtimeLookup,
networkLookup, devices)` uses the provider's existing runtime inspector. The lookup
must return current `state`, `pid`, `workload_id` and `assignment_generation` and,
when network collection is enabled, the existing application/service/image fields.
It cross-checks the PID cgroup against the containerd task cgroup before accepting
CPU, RAM and block counters. Replacement or stopped runtimes fail closed.

The optional network lookup supplies the existing owned-veth binding. Device
bindings specify `asset_id`, `resource_id`, `device_path`, `sysfs_path`, `driver`,
`driver_version_hash` and `profile_hash`. The device collector verifies a supported
Linux character device, its sysfs major/minor identity, approved driver/version,
and the matching device in the task's filesystem. It measures assignment presence,
not utilization or independent isolation. Review the deployed OCI device policy.

Whole-device reservation works only with a qualified binding. Unrecognized device
paths, absent drivers/version interfaces, unsupported vendor partitions and missing
hardware return unavailable. No GPU/NPU utilization, TOPS conversion or implicit
vendor qualification is implemented or required for the reservation fallback.

## Storage services performing real operations

Use `MeteredStorageAdapter` inside the existing authenticated service boundary:

```php
$storage = new MeteredStorageAdapter(
    $privateTenantRoot, $privateIntervalJournal, $scope,
    $assetId, 'object', $logicalQuotaBytes, $authorizeFunding, $meterAssetMapping
);
$result = $storage->execute($stableEventId, 'put', $objectId, $bytes);
$result = $storage->execute($readEventId, 'get', $objectId);
```

`nas`, `object` and `backup` profiles support bounded actual file I/O. `restore` is
backup-only. Object IDs and stable event IDs are 32-byte lowercase hexadecimal
identities, not paths. Content is immutable until explicit `delete`. A response is
bounded to 16 MiB; use multiple separately identified chunks for larger objects.
The adapter does not stream an unbounded upload or fetch a caller-supplied URL.

The funding callback must revalidate the current finalized commitment for every
operation; omission denies I/O. Authentication and delivery to the external client
remain the host application's responsibility. Read byte accounting means bytes
returned to that service, not proof that an Internet recipient acknowledged them.

The private root binds chain/provider/workload/generation and storage policy.
Recurring commitments can retain the root while using separate interval journals.
No symlinks, foreign files, hard links or nested paths are admitted. A root supports
at most 10,000 objects and enforces the explicit logical-byte quota. Reservations
are single-copy, uncompressed logical capacity; backing NFS replication,
compression or physical durability are not inferred. NAS protocol deployment and
object HTTP authentication are existing service concerns, not new meter authority.

If request/egress dimensions use different native capability assets, explicitly
supply `meterAssetMapping`, e.g. `['storage.object.egress' => $egressAsset]`. Include
those exact assets in the funded manifest. Different units must not share an
ambiguous capability asset.

Operation intent is durable before I/O. Completion records actual operations and
bytes. Repeated IDs do not repeat side effects. An interrupted operation remains
PENDING and blocks evidence export until an operator reconciles its outcome.
Inspect it without replaying I/O:

```sh
php scripts/resource-metering.php operation-status PRIVATE_DIR SCOPE_JSON EVENT_ID
```

Do not mark an uncertain operation complete merely to unblock billing. Preserve
partial files and raw operation evidence for reconciliation.

## Signed collection and native settlement

`ResourceCollectionSession::forHosted` accepts the existing `HostedFundingGuard`,
exact assignment document, `ResourceHostCollector`, explicit source signing key,
telemetry journal, allocator and storage adapters. It rechecks funding each sample,
uses the accepted manifest, obtains sequence from persistent state, and signs the
combined observations. CPU/RAM reservations require both leases and observed
cgroup limits. Accelerator reservations additionally require device availability.
Duplicate sources for a resource line are rejected rather than double-counted.

The existing `HostedMeteringMonitor::forProvider` accepts an optional final
`resourceCollector` factory `(runtimeRecord, fundingResult) -> ResourceCollectionSession`.
The same periodic provider loop invokes that session after its existing cgroup
checkpoint. No factory means the existing metering behavior is unchanged. Build
this factory from protected local configuration; do not accept paths, keys or
collector definitions from customer requests.

For finite jobs, compose the same session with the existing finalized compute
assignment/funding validator. Start sampling before work and close with
`sample(true)` before releasing the runtime. For hosted intervals, explicitly close
at an attributable boundary; a poll after expiry does not invent an exact split.
Runtime replacement, source incarnation changes, missing observations and expired
funding remain reconciliation cases. Never restart counters under the same scope.

Get closed evidence from `ResourceTelemetryJournal::evidence`. The existing
`ResourceSettlementWorkflow` then prepares the exact accepted bootstrap or
independent claim, followed by explicit signing, durable outbox submission,
certified inclusion, challenge handling and native settlement. The adapters cannot
transfer funds. Bootstrap never grants independent-attestation subsidy eligibility.

## Reproduce the source integration checks

```sh
php tests/Economics/resource-adapters.php
GWD_STORAGE_IO=1 php -d ffi.enable=true tests/Economics/bootstrap-operator-settlement.php
```

The second command needs the pinned Wasmtime library and registry WASM described in
the self-update guide. It performs real local file operations and settles their
signed evidence through the native engine. CPU/runtime identity in that test is an
explicit fixture; it does not claim physical containerd or independent verification.
