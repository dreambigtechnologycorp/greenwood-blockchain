# Implemented network capabilities

This page is a capability map. [Project status](../PROJECT_STATUS.md) records the
release boundary; [current functionality](../CURRENT_FUNCTIONALITY.md) explains
operational constraints. No feature here implies a required machine topology.

| Capability | What the implementation provides | Read next |
| --- | --- | --- |
| Consensus | Genesis-fixed equal-power validators, strict greater-than-two-thirds certificates, persistent signing safety | [Consensus](../concepts/consensus.md) |
| Transactions and state | Canonical signed envelopes, deterministic transitions, verified block replay | [Transactions](../concepts/transactions.md), [state](../architecture/state.md) |
| Contracts | Restricted deterministic Wasmtime host ABI, gas/fuel and bounded memory, transactional effects | [Contract quickstart](../contracts/quickstart.md) |
| Compute jobs | Finalized requests, capabilities, assignment and receipts; provider-side execution | [Compute v1](../GREENWOOD_COMPUTE_V1.md) |
| Hosted applications | Finalized application authority plus signed physical assignment, receiver acknowledgement, edge and runtime checks | [Hosted workflow](../CURRENT_FUNCTIONALITY.md#hosted-applications) |
| Resource settlement | GWD funding and GRU accounting, qualified resource profiles, explicit subsidy and trust rules | [Economics](../economics/README.md), [metering](../guides/RESOURCE_METERING.md) |
| Operations | Role-based Ansible configuration, pinned artifacts, credential boundaries, guarded storage and recovery | [Operator onboarding](../operations/ROLE_BASED_ONBOARDING.md) |

Off-chain compute results are not automatically replayed or independently proven
by consensus. A local observer is not independent hardware attestation. The
signing journal protects against conflicting local signatures; it is not a
general Byzantine-fault detector. Eligibility, metering and settlement must use
the actual approved trust class.

Run verification through the [test entrypoints](../testing/overview.md), not by
executing PHPUnit class files directly. To explore without affecting an existing
network, use the [disposable quickstart](quickstart.md).
