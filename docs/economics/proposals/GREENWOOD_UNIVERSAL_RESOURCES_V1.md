# Universal resources — candidate protocol mapping

This proposal maps to existing ResourceCatalog **version 2**, capability extension
type 14 and resource quote version 2; its filename is not a wire-version change.
See `src/Resources/` and `docs/operations/resource-metering.md`.

CPU, GPU, NPU, specialized accelerators, VRAM, RAM, SSD/NVMe/HDD, block/NAS/object,
network, endpoint, node and backup definitions use exact integer atoms. No calendar
month conversion enters consensus. Reserved capacity is distinguished from consumed
work. Composite manifests commit rates, limits, assets, exclusivity/billing groups,
included allowances and overages. Round each line to GRU, sum, then convert once to
GWD. Existing ServiceSettlement owns escrow, subsidy split, payouts and refunds.

All default payable dimensions remain off. A supported schema is not an operational
qualification. New resources cannot be paid merely because their class is advertised.
Finer metering/tenant attribution and physical trust profiles require qualification.
No changes to 1B supply, six decimals, 60-second target, no burn, GRU nontransferability,
emissions or either unratified allocation are proposed here.
