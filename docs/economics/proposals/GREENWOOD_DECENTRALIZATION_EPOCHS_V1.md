# Decentralization epochs — candidate

> **Historical scope — reviewed November 24, 2026.** This archived proposal/review preserves its original assumptions, recommendations and test reports. It does not independently adopt policy or describe the latest implementation.
> Read the [economics index](../README.md) for current source and policy precedence,
> and [project status](../../PROJECT_STATUS.md) for release/installation and qualification.

E0 discloses founder control. E1 counts qualified provider domains. E2 adds qualified
independent measurement and witness roles. E3 is rejected: dynamic validator membership
does not exist in this extension. Provider joins never change issuance, emissions,
validator votes, treasury signers or previous contracts.

Epoch parameters are explicit governance records: provider/witness domain thresholds,
profile, sustained blocks, participation evidence age, notice, activation and expiry.
No production values have been chosen. Qualification requires both sustained active
records and a reviewed past participation interval spanning the required duration.
It cannot rely solely on registration age, host count or keys. Governance-reviewed
participation is an external assertion, not a live cryptographic uptime oracle.

Changes require existing four-signer timelock. New independent admissions also
re-evaluate current role/source eligibility. Loss of qualification cannot rewrite a
funded trust policy or downgrade to founder telemetry. Stronger approved epochs
cannot be weakened by a later epoch record. Existing governance/validator authorities
do not acquire automatic founder sunsets in this candidate; their migration and
custody require a separately authorized upgrade.
