# Greenwood GRU v2 — Universal Resource Metering and Billing

**Greenwood needs to charge for every billable resource a provider supplies, not just CPU execution.**

The existing `compute_cpu_v1` and `hosted_cpu_v1` adapters should become two supported workload types within a **unified resource-accounting system**.

The objective is to support everything from a Raspberry Pi or Orange Pi cluster to a GPU server, NAS, storage node, or commercial data center.

## 1. Complete resource catalog

| Resource | Billing dimensions | Metering basis |
|---|---|---|
| **CPU** | vCPU-hours, CPU execution time | Reserved capacity and cgroup CPU counters |
| **GPU** | GPU-hours, utilization, VRAM-hours | Device allocation and supported GPU telemetry |
| **NPU** | NPU-hours, accelerator time | Device allocation and vendor-specific telemetry |
| **RAM** | GiB-hours | Reserved memory over time |
| **SSD** | GB-months, reads/writes, IOPS | Allocated storage, usage and I/O counters |
| **NVMe** | GB-months, IOPS, throughput | Storage allocation and I/O telemetry |
| **HDD** | GB-months, IOPS | Allocated capacity and disk operations |
| **NAS** | GB-months, operations, transfer | Shared storage allocation and access logs |
| **Object storage** | GB-months, requests, transfer | Stored bytes and object operations |
| **Block storage** | GB-months, IOPS, throughput | Volume allocation and block I/O |
| **Network** | GB transferred, Mbps capacity | Interface traffic and bandwidth reservations |
| **Public IP** | Address-hours | Assigned address duration |
| **GPU VRAM** | GiB-hours | Reserved accelerator memory |
| **Persistent volumes** | GB-months, operations | Provisioned capacity and I/O |
| **Snapshots/backups** | GB-months, operations | Retained snapshot bytes and operations |
| **Dedicated nodes** | Node-hours | Reserved physical capacity |
| **Specialized accelerators** | Device-hours, operations where verifiable | Hardware-specific adapters |

**GRU remains the common accounting denomination. GWD remains the settlement currency.**

Each resource needs a precise measurement definition, rate, billing interval, and verification policy.

Not every resource should be charged solely on utilization. RAM, SSD capacity, dedicated GPUs, and network reservations have real capacity costs even when idle.

The billing model must account for both **reserved capacity** and **measured consumption**, while explicitly preventing double charging.

## 2. Billing must distinguish reservation from consumption

There are two different things Greenwood can sell.

**Reserved capacity:** A customer pays for resources made available exclusively or under a defined service guarantee.

**Metered consumption:** A customer pays for resources actually consumed.

| Resource | Primary billing model | Optional additional charge |
|---|---|---|
| CPU | Reserved vCPU-hour or consumed CPU-second | Usage above included allowance |
| GPU | Reserved GPU-hour or accelerator partition-hour | Additional verifiable usage |
| NPU | Reserved NPU-hour | Verified utilization where supported |
| RAM | Reserved GiB-hour | Usage beyond included allocation |
| SSD/NVMe/HDD | Provisioned GB-month | Reads, writes, IOPS or throughput |
| NAS | Allocated GB-month | Operations and network transfer |
| Object storage | Stored GB-month | API requests, retrieval and transfer |
| Network | Transferred GB | Reserved bandwidth |
| Dedicated server | Node-hour | Separately disclosed excluded resources |
| Backups | Retained GB-month | Snapshot creation and restore operations |

**The fundamental rule: never bill the same resource twice without an explicitly agreed pricing structure.**

For example, if a customer rents a dedicated server that includes 32 GB of RAM, Greenwood cannot also charge separately for those same 32 GB unless the accepted quotation explicitly itemizes them outside the server rate.

Likewise, NAS storage backed by SSDs should not automatically generate both a NAS-capacity charge and an underlying SSD-capacity charge to the same customer.

The underlying costs remain relevant to the provider's profitability, but that is separate from customer billing.

---

# 3. Existing Linux metering capabilities

Greenwood's existing containerd integration already provides a foundation.

The Linux cgroup v2 interface supplies CPU usage, memory statistics, and block-I/O counters.

Specifically:

| Linux interface | Useful measurements |
|---|---|
| `cpu.stat` | CPU execution time |
| `memory.current` | Current memory usage |
| `memory.peak` | Maximum observed memory usage |
| `io.stat` | Read/write bytes and operations |
| Network interface statistics | Transmitted and received bytes |

These are documented kernel interfaces. Their availability and attribution still depend on the host configuration. [Kernel.org](https://www.kernel.org/doc/html/latest/admin-guide/cgroup-v2.html?utm_source=chatgpt.com)

For Greenwood, I would extend the current `CgroupAccounting` implementation rather than introducing another general-purpose container monitoring stack.

The important distinction is that **cgroup metrics are provider-side measurements, not independently verified proof of consumption**.

They need to flow into the existing attestation mechanism before subsidized settlement.

## GPU metering

NVIDIA DCGM provides GPU utilization, memory usage, process information, and other telemetry. AMD provides comparable device and process metrics through AMD SMI, although supported fields differ by hardware and driver. [NVIDIA Docs](https://docs.nvidia.com/datacenter/dcgm/latest/reference/dcgm-exporter-metrics.html?utm_source=chatgpt.com)

Greenwood should implement vendor-specific adapters.

| Adapter | Hardware |
|---|---|
| `NvidiaGpuMeter` | Supported NVIDIA GPUs |
| `AmdGpuMeter` | Supported AMD GPUs |
| `GenericGpuReservationMeter` | Devices without qualified usage telemetry |

A GPU must advertise its actual model, available memory, supported partitioning, and compatible runtime.

An RTX 4090 and an NVIDIA T4 should not automatically have identical GRU rates.

Neither should a whole GPU and a fractionally allocated GPU.

For the first production version, **reserved GPU-hours are a more practical billing unit than attempting to convert GPU utilization percentages into work performed**.

Utilization can support performance reporting and additional qualified usage models.

## NPU metering

NPU support requires a separate hardware abstraction.

This matters for Greenwood's SBC infrastructure.

A provider might supply an integrated NPU rather than a conventional GPU.

The resource system should support:

- Rockchip NPUs.
- Supported Intel NPUs.
- Supported AMD NPUs.
- Qualcomm accelerators.
- Other vendor-specific inference accelerators.

Each adapter must identify the actual hardware and supported measurement interfaces.

There is no justified universal conversion such as:

**1 NPU-hour = 1 GPU-hour.**

Those resources may have very different performance characteristics.

For NPUs without reliable per-workload utilization telemetry, Greenwood can charge for verified exclusive reservation duration, but should not claim to measure actual NPU execution time.

---

# 4. Expand the provider capability registry

The existing provider registry already advertises CPU and memory capacity.

That is insufficient for universal resource scheduling.

The next provider capability model should support:

| Capability | Advertised information |
|---|---|
| CPU | Architecture, cores, available capacity |
| GPU | Vendor, model, device identity, VRAM, partitions |
| NPU | Vendor, model, runtime support, accelerator capacity |
| RAM | Available memory and reservable capacity |
| Storage | Device type, available capacity, persistence and durability class |
| NAS | Exported service, allocated capacity, supported protocols |
| Network | Connectivity class, bandwidth, transfer limits |
| Location | Advertised region and availability zone |
| Availability | Capacity reservation and service availability |
| Trust | Supported attestation and verification mechanisms |

Every provider should advertise only the resources it can actually supply.

For example:

```text
Provider: Orange Pi 5
Architecture: ARM64

CPU: Available
GPU: Device-specific support
NPU: Available if runtime qualified
RAM: Available
Local Storage: Available
NAS: Optional
Network: Available
```

The exact quantities must come from the registered hardware, not assumed device specifications.

The scheduler should match resource requirements against those capabilities.

**Do not create separate schedulers for GPU, NPU, SSD, and NAS.**

Extend the existing provider capability and reservation abstractions with typed resource requirements.

Because the current compute wire formats are frozen, this should use a versioned capability and resource-manifest extension rather than silently modifying existing serialized objects.

---

# 5. Introduce a versioned universal GRU catalog

The existing economic implementation uses `compute_cpu_v1` and `hosted_cpu_v1`.

Those become supported catalog profiles rather than the only billable services.

I recommend a resource catalog containing stable identifiers such as:

```text
compute.cpu.reserved
compute.cpu.consumed

compute.gpu.reserved
compute.gpu.vram

compute.npu.reserved

memory.ram.reserved

storage.ssd.capacity
storage.nvme.capacity
storage.hdd.capacity

storage.block.capacity
storage.block.read
storage.block.write
storage.block.iops

storage.nas.capacity
storage.nas.operations

storage.object.capacity
storage.object.requests
storage.object.egress

network.ingress
network.egress
network.bandwidth.reserved

infrastructure.node.reserved

backup.snapshot.capacity
backup.snapshot.restore
```

Each catalog entry needs a formal definition.

| Field | Purpose |
|---|---|
| `resource_id` | Stable resource identifier |
| `unit` | Canonical billable unit |
| `measurement_type` | Reserved or consumed |
| `meter_version` | Measurement specification |
| `attestation_policy` | Required evidence and signers |
| `capacity_class` | Hardware/performance class |
| `rate` | Provider-accepted GWD/GRU quotation |
| `maximum_quantity` | Authorized consumption ceiling |
| `rounding_policy` | Deterministic fixed-point calculation |

Rates should be represented as exact rational integers.

Avoid floating-point arithmetic in the economic state.

The provider can advertise different prices for different resource classes. The accepted quote commits to the exact catalog version and all applicable rates.

---

# 6. One quote can contain multiple resources

A customer requesting application hosting should receive one resource quotation covering the complete service.

For example:

**Requested workload**

| Resource | Allocation |
|---|---|
| CPU | 2 vCPU |
| RAM | 4 GiB |
| Persistent SSD | 20 GB |
| Network transfer | Up to 50 GB |
| GPU | None |
| NPU | None |

The quote should specify the resources, duration, rates, and maximum charge.

For an AI workload, the quote may additionally include:

- GPU model and reserved accelerator time.
- GPU memory allocation.
- NPU device time.
- Model-storage requirements.
- Network transfer.

### Unified charge formula

For a single authorized billing interval:

\[
\text{Total GRU}=\sum_{i=1}^{n}
\left(\text{Billable Quantity}_i \times \text{GRU Rate}_i\right)
\]

The accepted provider quotation determines the GWD settlement rate.

\[
\text{GWD Charge} =
\text{Authorized Conversion}(\text{Total GRU})
\]

All quantities and rates must use the exact fixed-point and rational arithmetic already established in Greenwood's native economic foundation.

The system must enforce both the per-resource ceilings and the aggregate payment ceiling.

**The provider should not be able to add a new resource charge after the customer accepts a quote.**

Additional resource consumption requires an authorized quote amendment or a new funded interval.

---

# 7. Integrate with the existing escrow and receipt system

The current settlement implementation already has the correct structure:

```text
Approved Program
      |
Provider-Signed Offer
      |
Customer Authorization
      |
Native GWD Escrow
      |
Funded Assignment
      |
Provider Execution
      |
Usage Attestation
      |
Settlement / Refund
```

The universal resource model expands what the quotation and usage evidence contain.

It does not change the purpose of the settlement engine.

## Proposed `ResourceUsageEvidenceV1`

A new evidence envelope should bind:

```text
chain_id
genesis_hash

service_commitment_id
provider_id
application_or_compute_id

assignment_generation
billing_interval_id

resource_catalog_hash
resource_manifest_hash

meter_id
meter_version

resource_measurements
measurement_start
measurement_end

runtime_identity
device_identities

evidence_bundle_hash
attestor_signatures
```

The actual canonical encoding must be versioned, bounded, and unambiguous.

Resource measurements should be typed integers, not generic floating-point JSON values.

The evidence should support multiple meters without requiring every service to produce measurements for every resource type.

**Preserve `ComputeReceiptV2`.** It remains the execution receipt.

The additional evidence object authorizes usage accounting.

Both reference the same finalized service commitment.

---

# 8. Hosted services and compute jobs use the same billing engine

The existing hosted-settlement report confirms that the CPU adapter already reuses native `ServiceSettlement`. That should remain the shared financial implementation. Pasted text(20261114-154649)

### Finite compute jobs

A job declares its required resources and maximum cost.

Greenwood authorizes funding before assignment.

The provider executes the job.

The resource collectors measure relevant consumption.

The attestation mechanism verifies the accepted evidence.

The settlement engine calculates the final charge and refunds unused funding.

### Hosted applications

A hosted application has recurring funded intervals.

Each interval specifies its reserved and metered resources.

The existing deployment controller and provider operator continue managing the runtime.

The settlement engine bills the supported resource dimensions for that interval.

Funding for the next interval must be finalized before Greenwood authorizes continued service.

A runtime restart must not reset usage counters, create a second billing entitlement, or duplicate charges.

The original containerd lease continues to manage runtime resources; the financial lease remains a separate identifier.

---

# 9. Metering and verification policy

Not all resource classes provide equally strong evidence.

I would classify meters into three levels.

| Level | Evidence | Production treatment |
|---|---|---|
| A | Independently observable allocation and usage with qualified reconciliation | Eligible for verified settlement |
| B | Provider telemetry corroborated by independent controls or bounded observations | Eligible under an explicitly accepted trust policy |
| C | Provider self-report only | Not sufficient for Institute-subsidized verified usage |

**Two signatures over the same unverified telemetry do not constitute independent measurement.**

This is particularly important for GPU, NPU, storage, and network billing.

A provider could otherwise inflate consumption and drain the subsidy reserve.

For dedicated reservations, the relevant evidence may be proof of allocation and availability rather than utilization.

For network usage, measurement must be attributable to the correct workload or service; host-wide traffic counters are insufficient for per-customer billing.

For storage, the meter must distinguish allocated capacity, actually stored bytes, logical versus physical bytes, and read/write operations.

Every class needs a qualified collection and verification profile before accepting payable claims.

---

# 10. Capacity enforcement and economic limits

A service quote must not authorize unlimited consumption.

Greenwood needs limits at three layers.

**Resource enforcement:** CPU quotas, RAM limits, storage quotas, accelerator assignments, and network limits wherever supported.

**Financial enforcement:** Maximum GWD escrow, per-resource charging ceilings, subsidy budgets, and total service limits.

**Operational enforcement:** Stop, restrict, or require renewal when the authorized commitment expires.

The existing polling-based hosted funding monitor is a foundation, but not proof of immediate cutoff.

A rapidly consuming resource, particularly GPU inference or outbound networking, can incur substantial cost before the next poll.

Meter collection cadence and maximum exposure must therefore be designed together. When a hard physical cutoff is unavailable, Greenwood must not promise a hard usage ceiling.

Missing telemetry must not be interpreted as zero usage or as valid proof of unlimited usage.

---

# 11. Required tests

The new integration must pass a resource-by-resource test matrix.

| Test | Required result |
|---|---|
| CPU exceeds funded quantity | Bounded or rejected according to policy |
| GPU device differs from quote | Rejected |
| GPU utilization missing | No unsupported utilization charge |
| NPU lacks qualified meter | Reservation-only billing or rejection |
| RAM peak below reservation | Correct reserved-capacity billing |
| SSD volume exceeds quota | No unauthorized expansion |
| NAS usage reported by another tenant | Excluded |
| Network interface counters include host traffic | Not billed to customer |
| Storage billed through NAS and backing SSD | No duplicate charge |
| Accelerator shared by multiple services | No duplicate exclusive allocation |
| Application restarts | Billing identity preserved |
| Usage collector crashes | Reconciliation required |
| Attestors disagree | Dispute or rejection according to policy |
| Accepted quote expires after funding | Existing commitment follows its accepted terms |
| Resource rates change | Existing funded interval remains unchanged |
| Subsidy budget exhausted | New commitments rejected |
| Escrow insufficient | New resource authorization rejected |
| Settlement replay attempted | No second payment |
| Historical records archived | Replay prevention preserved |

The test suite should cover exact arithmetic, adversarial evidence, independent attestation, persistence, state-root equivalence, actual containerd behavior, and distributed finality.

---

# 12. Implementation sequence

I would divide this work into four engineering stages.

| Stage | Deliverable |
|---|---|
| **1. Resource catalog and capabilities** | Versioned CPU, GPU, NPU, RAM, storage, NAS, and network definitions; provider hardware registration; composite quotations |
| **2. Measurement adapters** | Cgroup, GPU, NPU, storage, NAS, and network collectors with explicit attribution and evidence formats |
| **3. Unified settlement** | Extend existing `ServiceSettlement` to support multiple resource lines, independent attestation, caps, escrow, and refunds |
| **4. Physical acceptance** | Test actual hardware, recurring hosting, compute jobs, failures, device isolation, billing, and payment finality |

The first stage should define all resource types, but unsupported measurements must remain disabled for payable usage.

This avoids claiming universal metering before the underlying devices and verifiers are qualified.

## Final architecture

```text
                  GREENWOOD RESOURCE MARKETPLACE
                              |
                    PROVIDER CAPABILITIES
                              |
        +----------+----------+----------+----------+
        |          |          |          |          |
       CPU        GPU        NPU        RAM       STORAGE
        |          |          |          |          |
        +----------+----------+----------+----------+
                              |
                    NAS / NETWORK / OTHER
                              |
                              v
                      GRU RESOURCE CATALOG
                              |
                              v
                     SIGNED PROVIDER QUOTE
                              |
                              v
                      CUSTOMER ACCEPTANCE
                              |
                              v
                         GWD ESCROW
                              |
                              v
                 EXISTING PROVIDER ASSIGNMENT
                              |
                              v
                        EXECUTION
                              |
                              v
                   RESOURCE MEASUREMENT
                              |
                              v
                    USAGE ATTESTATION
                              |
                              v
                  NATIVE SERVICE SETTLEMENT
                              |
                +-------------+-------------+
                |                           |
                v                           v
          PROVIDER PAYMENT              GWD REFUND
```

**The architectural decision should be to make GRU a universal resource-accounting protocol, not a CPU billing system.**

CPU, GPU, NPU, RAM, SSD, NVMe, HDD, NAS, object storage, network bandwidth, and future hardware classes should all use the same quotation, funding, attestation, and settlement framework.

They should differ only in their resource definitions, measurement methods, and verification requirements.

That preserves Greenwood's existing provider, lease, receipt, Application Registry, and native economic infrastructure while extending it to the full range of physical computing resources.