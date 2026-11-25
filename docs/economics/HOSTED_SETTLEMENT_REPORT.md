# Hosted recurring settlement — implementation report

> **Historical scope — reviewed November 24, 2026.** This hosted-CPU milestone predates the fresh-production requirement for explicit resource V2 trust profiles. Test counts and environment paths remain historical.
> Read the [economics index](README.md) for current source and policy precedence,
> and [project status](../PROJECT_STATUS.md) for release/installation and qualification.

November 14, 2026. **Local candidate implementation; mainnet HOLD remains.** The latest supplied review is archived under `proposals/GRU_IMPLEMENTATION_REVIEW_2026-11-14.md`. No permanent genesis, production switch, wallet, user payment, live deployment or remote push changed.

## Delivered

`Application Registry WASM → approved assignment document → provider-signed interval offer → native escrow → finalized funding guard → existing controller/operator → interval usage quorum → shared payment/refund engine → next funded interval`

The hosted CPU adapter reuses `ServiceSettlement`. It has no second balance system, scheduler, currency or provider daemon. Six-decimal fixed-supply GWD, no burning, operator transaction fees, validator emissions, 60-second target blocks and the unratified allocation candidates are unchanged.

- Governance can approve immutable `hosted_cpu_v1` programs with pinned registry/code identity and the existing program budgets/attestor policy. Catalog hashing commits the hosted CPU billing rules.
- A signed type-13 `fund` action accepts a full existing assignment document plus future `start_height`, bounded `end_height`, CPU budget, maximum GWD charge and registry authority epoch. The provider quote must sign the exact hosted-binding hash. Frozen finite-compute wire formats remain unchanged.
- Validators run the **actual pinned Application Registry WASM** read-only against native state. Funding checks `qap`, `qau`, `qre`, `qrs`: effective DEPLOY capability, ownership epoch, active release, service image digest and ABI/code identity. Query effects cannot mutate money or storage. It does not trust an off-chain registry proof.
- Application/registry/service scope and provider/runtime-instance scope cannot overlap across billable intervals, even using another assignment generation or quote. Adjacent intervals are independently funded. Generation and interval bindings use explicit adapter namespaces; accepted offers remain single use.
- Both customer and subsidy contributions enter the existing native escrow. All existing rolling caps, outstanding commitments, related-beneficiary/program budgets, GRU ceilings and exact rounding still apply. No additional GWD is issued.
- Hosted usage is only payable after its interval ends. The quorum signs chain/genesis, commitment, assignment generation, interval, catalog, bounded CPU consumption, measurement-bundle hash and runtime-lineage hash. The same challenge, dispute, payment and funding-source refund code handles both adapters.
- Runtime financial status is separate from execution readiness and payment finality. `settle` remains an explicit signed transaction, relayable by any fee-paying ordinary account after maturity; there is no claim that a provider daemon automatically collects independent evidence or broadcasts every payment.

## Existing controller/provider integration

The deployment controller durably exports its reserved assignment before waiting for funding. It checks the funded interval before assignment-distribution side effects, before first runtime start and when reconciling a saved operation. Retries preserve the assignment and interval identity and do not duplicate start.

Provider startup and the recovery executable read the **existing receiver's COMMITTED assignment journal**, never a caller-supplied financial proof. A prepared assignment cannot authorize runtime start. The funding guard compares the exact document/generation, provider, chain/genesis, finalized height and active interval. The public funding endpoint rechecks current registry authority, so ownership/release revocation denies continued runtime authorization.

The existing provider poll loop now runs `HostedServiceMonitor`. Expired, missing or unreadable funding triggers managed network cleanup and the existing operator stop path. Both operations are attempted; uncertain cleanup/stop is reported for retry, not treated as confirmed restriction. Startup checks funding before image/runtime side effects, again immediately before runtime start and before returning network readiness. Recovery checks before task resume and network reconciliation and never loads a signing key solely for recovery.

This is polling enforcement, not a demonstrated hard real-time cutoff. Runtime CPU-budget enforcement, halt/watchdog behavior and physical cleanup/restart behavior still require qualification; see the independent-metering plan.

Fresh-chain integration also removed historical chain-name restrictions from `ApprovedDeploymentStore` and the control-plane SPIFFE validator. TLS peers still require exact configured certificate fingerprints and URI pins; mixed chain namespaces are rejected. The controller verifies that its configured URI namespace matches its configured chain.

## Configuration and operation

Default controller behavior still rejects economic hosting with `HOSTED_SERVICE_SETTLEMENT_NOT_QUALIFIED`. No production configuration was changed. For an explicitly selected **disposable candidate** installation, protected controller configuration can set `hosted_settlement_candidate_enabled=true` and an explicit `hosted_provider_public_key`; `Bootstrap` then composes the real funded-interval guard. The flag is not evidence of qualification and must remain false in production until acceptance.

The updated provider and recovery entry points require `--hosted-funding-config=/absolute/protected/hosted-funding.json` for hosted application start/recovery. The file and ancestry must be root-owned and protected. It contains only public scope and the receiver-journal path:

```json
{
  "chain_id": "REPLACE_FRESH_CHAIN_ID",
  "genesis_hash": "REPLACE_GENESIS_HASH",
  "provider_public_key": "REPLACE_PROVIDER_PUBLIC_KEY",
  "receiver_ledger": "/var/lib/greenwood-control-plane/receiver/receiver.sqlite"
}
```

The receiver journal must actually be available locally through the approved installation; the implementation does not copy a journal from another node or infer its contents. Recovery also requires the provider configuration's pinned `expectedPublicKey`. The systemd recovery template adds `GREENWOOD_HOSTED_FUNDING_CONFIG`; review the template before installation. No service unit was installed or restarted.

Public lookup: `GET /v1/economics/hosted/funding/{assignment_generation}` returns only the currently active funded interval with chain/genesis, finalized height/hash/root and exact commitment. The client uses bounded reads from an administrator-selected origin, checks coherent before/after node commitments and denies redirects or arbitrary routes. This is a trusted-node transport boundary, not client verification of a validator certificate. Configure and qualify transport protection for the physical cluster.

For funding, use the existing `service-sign` CLI/PHP signing helpers. The hosted binding is:

```text
hosted = {document, start_height, cpu_budget_ms, max_payment_units, authority_epoch}
quote.binding_hash = HostedServiceBinding::hash(hosted + {end_height})
```

The end height belongs to the fund action and provider-signed binding. The quote's maximum GRU must fully cover the accepted CPU budget. Each interval needs a fresh signed offer; restarts do not create a new quote, interval or entitlement. Containerd `lease_id` remains resource-management metadata.

## Challenge and outage clarification

An unresolved dispute's refund cutoff is now final: a quorum cannot approve a late resolution after that cutoff to restart the payment delay and race the customer's refund. Tests cover council outage and protected-source restoration. Providers can still go unpaid when required independent evidence is unavailable; the protocol does not guarantee compensation for an unattested claim.

## Reproducing local checks

Use the already approved local AssemblyScript 0.28.20 compiler and Wasmtime 48.0.1 library. The build performs no dependency installation or broadcast:

```sh
GREENWOOD_ASSEMBLYSCRIPT_ROOT="$PWD/contracts/application-registry-next/node_modules/assemblyscript" \
  sh contracts/application-registry-next/build.sh /tmp/greenwood-hosted-registry.wasm
# Set GREENWOOD_WASMTIME_LIB to the approved local libwasmtime.so.
php -d ffi.enable=true tests/Economics/hosted.php /tmp/greenwood-hosted-registry.wasm
php tests/Economics/hosted-boundaries.php
php -d ffi.enable=true tests/Economics/hosted-certified-history.php /tmp/greenwood-hosted-registry.wasm
```

Node must be on PATH for the build. Enable the installed PDO SQLite extension for persistence/boundary/history tests. Qualification here used the matching local PHP extension at `/tmp/intown-php-sqlite/usr/lib/php/20250925/pdo_sqlite.so`, actual library `/tmp/wasmtime-v48.0.1-x86_64-linux-c-api/lib/libwasmtime.so`, and the Codex-bundled Node binary. Artifact/compiler provenance is recorded in `evidence/hosted-registry-artifact.json`. No alternate mock registry was used. The full history run took approximately 438 seconds locally.

## Validation and remaining HOLD

Both allocations passed **64 core, 68 finite-compute, 32 hosted and 25 certified-chain foundation checks** each. Shared checks: **17 hosted boundaries**, **9 fresh-chain identity**. The **12 deployment/registry regression suites**, fresh-chain loopback mTLS regression, and **131 PHP syntax checks** passed; `git diff --check` passed. Current test results are recorded in `evidence/hosted-integration-tests.json` and `evidence/hosted-fresh-mtls.json`. Tests execute the actual locally compiled Application Registry through Greenwood PHP/Wasmtime, actual native monetary transitions, actual receiver SQLite reads and controller/provider funding gates. Fixture runtime callbacks do not establish real container/network execution. Real mTLS tests use temporary loopback peers with a fresh chain namespace; they do not establish a physical distributed cluster.

A separate `hosted-certified-history.php` test builds from economic genesis through program approval/timelock, registry creation, two funded hosted intervals, attested payment and outage refund, then reconstructs the complete saved history with real three-validator certificates. It passed: **24,487 certified blocks, 8 assertions, identical post-restart state root**. The full history used the 40/20/15/24/1 allocation; hosted transition tests separately passed for both candidates. Evidence is in `evidence/hosted-certified-history.json`. This is single-process consensus testing, not distributed execution or independent measurement.

Remaining production requirements:

1. Demonstrate independent CPU observation and reconciliation, administrative separation, outage/conflict handling, runtime CPU cutoffs and stale-head/watchdog behavior. No signature-count claim substitutes for that evidence. See `INDEPENDENT_CPU_METERING_PLAN.md`.
2. Physically validate provider/controller/receiver deployment, funded renewal, application restart, ownership revocation, expiry restriction/stop, witness failure and real payment finality on a disposable cluster.
3. Implement durable pruning/archival with authenticated replay protection. Current admission caps of 1,000 programs/10,000 commitments remain; deleting financial records or consumed-quote bindings is not authorized.
4. Complete JavaScript SDK/product workflows and operational evidence/settlement submission. Existing execution-journal `SETTLED` semantics remain unchanged.
5. Ratify and implement treasury lost-quorum recovery, establish real operating costs, finish independent security qualification and separately authorize permanent genesis. Neither allocation is ratified.
