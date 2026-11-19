# Hyperscaler comparison and 25-application GRU budget

**DRAFT — November 18, 2026. No governance approval, keys, production changes or
provider pricing activation.** Scope: 25 web apps plus backends, ten DreamBIG-owned
and fifteen client projects. User requested application-agnostic sizing. Workload
quantities below are explicit scenarios, not measured requirements. USD throughout.

## Recommendation

Start planning against **50,650 whole GRU per 30 days** for the baseline portfolio,
including 25% contingency. Provisionally assign **20,260 GRU to DreamBIG's ten
apps** and **30,390 GRU to clients**, assuming equal resource usage. Replace that
40/60 split with per-app metering and shared-service attribution once running.
DreamBIG's 90-day envelope is **60,780 GRU**; the whole portfolio is **151,950 GRU**.
Do not require DreamBIG to sponsor all client workloads: clients should pre-fund
accepted quotes unless there is an explicit corporate sponsorship.

No GWD market price is assumed. The older [GRU economic model](GRU_ECONOMIC_MODEL.md)
proposed 1 GWD per whole GRU at genesis. **If separately adopted as a provider quote**,
the above numbers would also be GWD: 20,260 GWD/month for DreamBIG, 50,650 GWD/month
if it sponsors everyone. This is a pricing-policy choice, not a market-price finding.
The machine-readable budget leaves GWD unset until a quote is supplied.

Use already received DreamBIG validator distributions for its corporate envelope;
no discretionary reserve grant, subsidy or advance is created. Network bootstrap
funding remains separate. This supersedes the prior $0.10/GWD scenario as the basis
for the application budget; the earlier 10,000/30,000 GWD envelope is not ratified.

## Actual compute price comparison

Linux ARM, on-demand, 2 vCPU and 8 GiB per VM; no Spot, commitments or free trials.
Use **720 hours / 30 days**, not the usual 730-hour marketing month. Regions are
nearby US reference markets, not identical locations. CPU generations, performance
and operational availability are not identical.

| Provider | Region / SKU | USD/hour | 30-day VM | 128-GiB SSD capacity/month | VM + disk |
| --- | --- | ---: | ---: | ---: | ---: |
| AWS | us-east-1 / m7g.large | 0.0816 | 58.752 | 10.24 | 68.992 |
| Azure | eastus / Standard_D2ps_v6 | 0.0702 | 50.544 | 9.60 | 60.144 |
| Google | us-central1 / t2a-standard-2 | 0.0770 | 55.440 | 12.80 | 68.240 |

Compute rates were retrieved directly from the providers. [AWS price feed](https://b0.p.awsstatic.com/pricing/2.0/meteredUnitMaps/ec2/USD/current/ec2-ondemand-without-sec-sel/US%20East%20(N.%20Virginia)/Linux/index.json),
[Azure Retail Prices API](https://prices.azure.com/api/retail/prices),
[Google machine pricing](https://cloud.google.com/products/compute/pricing/general-purpose?hl=en).
Selected public response rows and Google page excerpts are saved in
[the evidence extract](../evidence/hyperscaler-pricing-2026-11-18/source-extracts.json).
Azure filtering excludes Windows, Spot and Low Priority; the disk calculation uses
`E10 LRS Disk`, not the additional shared-disk mount meter.

Disk assumptions: AWS gp3 at $0.08/GiB-month, Azure Standard SSD E10 128 GiB at
$9.60/month, Google balanced Persistent Disk at $0.10/GiB-month. These compare
capacity costs, **not equivalent IOPS, throughput or durability**. Azure disk
operation charges are extra and workload-dependent. Sources: [AWS units/rate](https://docs.aws.amazon.com/prescriptive-guidance/latest/optimize-costs-microsoft-workloads/ebs-migrate-gp2-gp3.html),
[Azure disk sizes](https://learn.microsoft.com/en-us/azure/virtual-machines/disks-types),
[Google block storage](https://cloud.google.com/products/block-storage).

Use the **median complete compute-plus-disk basket**, not a synthetic cheapest
combination of providers. Google is the median in these three samples. No arbitrary
CPU/RAM price split: RAM is included in the compute bundle, and cannot be billed
again as a separate positive resource charge for the same allocation.

## Sizing and common ancillary assumptions

Each app allocation includes its frontend/backend workers combined. Shared capacity
covers a modest self-managed database/cache and supporting services, not a managed
cloud database or resilient multi-zone deployment. No AI inference, video encoding,
large analytics jobs or dedicated VM per application is assumed.

| Scenario | Per app CPU / RAM | Shared CPU / RAM | Required CPU / RAM | Rounded cloud pool | Disk provisioned | Egress / retained backup |
| --- | --- | --- | --- | --- | --- | --- |
| Light | 0.125 / 0.5 GiB | 1 / 4 GiB | 4.125 / 16.5 GiB | 3 VMs: 6 / 24 GiB | 384 GiB | 100 / 100 GiB |
| Baseline | 0.25 / 1 GiB | 2 / 8 GiB | 8.25 / 33 GiB | 5 VMs: 10 / 40 GiB | 640 GiB | 500 / 200 GiB |
| Growth | 0.5 / 2 GiB | 4 / 16 GiB | 16.5 / 66 GiB | 9 VMs: 18 / 72 GiB | 1,152 GiB | 1,000 / 500 GiB |

Round pools up to whole VM units; the slack is not a guaranteed failover margin.
Persistent capacity includes OS and application data; backup is retained compressed/
incremental data, not automatically a full copy of every provisioned disk.

Use a **common ancillary benchmark**, explicitly not a full three-provider network/
backup comparison: Google North America Premium egress $0.12/GiB and regional
Standard object storage $0.02/GiB-month. These gross rates omit promotional/free
allowances for portability. All scenarios stay below 1,024 GiB egress. Backup
operation/restore charges remain unpriced. Sources: [network tariff](https://cloud.google.com/vpc/pricing),
[storage example](https://cloud.google.com/storage/pricing-examples).
These references do not authorize adopting Google storage as a production dependency.

| 30-day scenario | Median compute + disk | Egress | Backup | Reference subtotal | With 25% contingency | Whole GRU budget | DreamBIG 40% GRU |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Light | $204.72 | $12 | $2 | $218.72 | $273.40 | 27,340 | 10,936 |
| Baseline | $341.20 | $60 | $4 | $405.20 | $506.50 | 50,650 | 20,260 |
| Growth | $614.16 | $120 | $10 | $744.16 | $930.20 | 93,020 | 37,208 |

The contingency is an exposure allowance, not a quoted resource charge. Excluded:
Azure disk transactions, extra IOPS/throughput, backup requests/restores, load
balancer/NAT/public IP, managed databases, multi-zone redundancy, taxes/support,
email/SMS/AI APIs, Greenwood transaction fees, labor and transport. It is not proven
that 25% covers these; add actual requirements before committing a deployment.

## Mapping into existing GRU accounting

Reference: 1 whole GRU = $0.01 infrastructure value, **not a redeemable asset**.
The offline calculator uses `gru_scale = 1,000,000` atomic GRU per whole GRU and
Greenwood's integer `Amount` and `ResourceManifest::maximum` calculations:

- Compute bundle: $0.077 per two-vCPU/eight-GiB hour = 7.7 whole GRU/bundle-hour.
  Charge CPU reservations at 3.85 whole GRU/vCPU-hour with the accompanying 4 GiB
  per vCPU included. The RAM reservation line still records its allocation but has
  rate zero; accepted production coverage must declare both CPU and RAM.
- Disk: $0.10/GiB-month = 10 GRU/GiB over the modeled 30 days, converted into
  MiB-milliseconds with 1 GiB = 1,024 MiB.
- Egress: 12 GRU/GiB, converted into bytes with 1 GiB = 1,073,741,824 bytes.
- Backup capacity: 2 GRU/GiB over the modeled 30 days, also MiB-milliseconds.
- Ceil each resource's integer GRU contribution, sum, then convert once under the
  accepted provider quote. Reservations are billed as reservations; do not also
  charge CPU consumption for capacity already covered by the bundle.

At baseline: 27,720 compute GRU + 6,400 disk GRU + 6,000 egress GRU + 400 backup
GRU = 40,520 GRU before contingency. These are planning projections, intentionally
not complete deployable manifests: no fabricated asset identities, qualified
catalog entries, attestation approvals or signed provider offers are supplied.

For an accepted quote of `q` GWD base units per whole GRU:
`GWD base units = ceil(atomic_GRU * q / gru_scale)`, plus separately calculated
network fees. Six decimal places apply to GWD. Provider-specific workloads can
have different quotes; do not apply a single portfolio quote unless accepted.

## Physical capacity and operating economics

This is a **cloud replacement-cost reference**, not proof that the current Pi
cluster matches these performance or availability levels. Baseline requires 33 GiB
RAM before OS, validators, registry, metering, gateway and maintenance headroom.
Do not count node-4's infrastructure capacity or validator reservations as free
application capacity. Measure available RAM, sustainable CPU, SSD/NFS IOPS and
network throughput before accepting 25 concurrent application commitments.

The baseline $506.50 reference allowance is below DreamBIG's stated approximately
$1,000 monthly cash costs, excluding labor/transport. Benchmark parity therefore
**does not establish cost recovery**. If workloads are equally sized, 15 paying
clients represent only $303.90/month of this reference allowance; the ten owned
apps are internal consumption. Validator distributions and self-payments do not
produce outside cash without realizable liquidity. Full $1,000 recovery from only
15 clients would require $66.67/client/month before labor, taxes or margin, assuming
all infrastructure costs were assigned to them. That is a separate commercial
pricing decision, not an automatic GRU or token-value adjustment.

Refine the draft after measurements: per-app p95 RAM and sustained/burst CPU,
worker concurrency, database working set, storage growth, backup retention, outbound
traffic and recovery objectives. Keep GRU reference costs, actual provider cost,
customer price and GWD settlement exchange distinct.

## Reproduction and verification

From the repository root:

```sh
php scripts/draft-hyperscaler-budget.php
php tests/Economics/draft-hyperscaler-budget.php
```

[Inputs](../evidence/hyperscaler-pricing-2026-11-18/inputs.json) and
[results](../evidence/hyperscaler-pricing-2026-11-18/results.json) are versionable draft
artifacts. An optional input `gwd_quote_base_units_per_whole_gru` enables an explicit
quote calculation; it is null by default. Rates are dated snapshots, not refreshed
or approved automatically. The 32 keyless checks verify sizing, cost-to-GRU results,
allocation conservation, recognized resource identifiers and optional quote conversion.
No full release suite or physical acceptance was performed for this planning task.
