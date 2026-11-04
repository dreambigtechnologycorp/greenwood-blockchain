# Operator API guide

This file is a convenience entry point. The normative operator reference is
[`docs/operator-api.md`](../operator-api.md).

Operator routes are intentionally separate from public RPC:

- They are served only by an explicitly configured Unix-socket or loopback
  listener.
- Every route requires `Authorization: Bearer <token>`.
- A node exposes validator status/consensus routes; a provider exposes provider
  status, jobs, runtime, drain/resume, and capability routes.
- Provider drain/resume/capability mutations create canonical provider-capability
  transactions. Hosted-application reserve/start/inspect is a distinct private
  operational surface, not a capability transaction or developer-facing endpoint.
  See [provider operations](../provider-operations.md).

Use the [`greenwood` CLI](../cli.md) with `--operator` and `--token-file` for
normal local operation. Do not proxy an operator socket to an internet-facing
HTTP endpoint.

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Public API guide](public-api.md) · [Public RPC v1](../rpc-v1.md)
<!-- /docs-nav -->
