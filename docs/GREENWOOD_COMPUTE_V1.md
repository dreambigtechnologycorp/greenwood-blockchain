# Greenwood Compute Protocol V1

This document defines the Greenwood compute/contract execution protocol.

## 1. Architecture

```
+------------------+    +------------------+    +-------------+
|  greenwoodd      |    |  greenwood-provider |    |  containerd |
|  (blockchain)    |----|  (compute agent)  |----|  (runtime)  |
+------------------+    +------------------+    +-------------+
```

## 2. Objects

### 2.1 Provider

```
provider_id: string
owner_address: 32-byte address
status: pending | active | inactive
cpu_capacity: u32 (milli-cores)
memory_capacity: u64 (bytes)
endpoint: string
```

### 2.2 Workload

```
workload_id: string
owner: 32-byte address
provider_id: string
image: string
image_digest: string (mandatory)
command: array of strings
args: array of strings
env: map of string -> string
cpu_millis: u32
memory_bytes: u64
ports: array of port mappings
status: pending | running | stopped | failed
```

### 2.3 WorkloadReceipt

```
workload_id: string
provider_id: string
exit_code: i32 (optional)
started_at: u64 (optional)
stopped_at: u64 (optional)
logs_hash: 32 bytes (optional)
health_status: healthy | unhealthy | unknown
```

## 3. DeployWorkload Transaction

```
DeployWorkload payload:
    workload_id: length-prefixed string
    provider_id: length-prefixed string
    image: length-prefixed string
    image_digest: length-prefixed string
    command: array of length-prefixed strings
    args: array of length-prefixed strings
    env: map of length-prefixed key-value pairs
    cpu_millis: u32
    memory_bytes: u64
```

## 4. Execution Model

### 4.1 Chain Execution

```
block validation
    -> validators finalize workload assignment
```

### 4.2 Provider Execution (OFF-CHAIN)

```
provider watches finalized state
    -> identifies assigned workloads
    -> verifies assignment
    -> pulls exact OCI image digest
    -> creates container via containerd
    -> applies resource limits
    -> starts container
    -> monitors health
    -> submits workload receipt
```

**IMPORTANT**: Container execution MUST NOT affect consensus determinism.

## 5. Provider Agent (greenwood-provider)

```
bin/greenwood-provider
```

Responsibilities:

- Watch finalized Greenwood state
- Find workloads assigned to this provider
- Verify provider assignment
- Pull OCI images by exact digest
- Create/start containers through containerd
- Enforce CPU/memory limits
- Monitor health
- Submit workload receipts

## 6. containerd Integration

Preferred: PHP direct gRPC integration to containerd API.

Fallback: Small local transport adapter (no protocol logic).

### 6.1 Image Pulling

```
image_digest = sha256:<digest>
```

MUST use exact digest, never mutable tags like "latest".

### 6.2 Resource Limits

```
CPU: u32 milli-cores
Memory: u64 bytes
```

### 6.3 Persistent Volumes

```
/var/lib/greenwood/volumes/<volume_id>/
```

Provider-local volumes only for v1.

## 7. Receipt Submission

Workload receipts are submitted as transactions to finalize state.

```
SubmitWorkloadReceipt payload:
    workload_id: length-prefixed string
    provider_id: length-prefixed string
    exit_code: i32
    started_at: u64
    stopped_at: u64
    logs_hash: 32 bytes
    health_status: i32
```

## 8. Health Checks

Providers should monitor workload health:

- HTTP endpoint health checks (if configured)
- Container exit status
- Resource usage metrics

## 9. Error Handling

- Image pull failure -> workload marked failed
- Container start failure -> workload marked failed
- Health check failure -> provider reports unhealthy status

## 10. Security

- Image digest verification mandatory
- Resource limits enforced
- No host filesystem access
- No network access (unless explicitly allowed by workload)

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](README.md) · [Provider operations](provider-operations.md) · [Compute security](compute-security-v1.md)
<!-- /docs-nav -->
