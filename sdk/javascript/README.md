# Greenwood resource customer SDK

Local Node.js module; it neither publishes packages nor broadcasts transactions.
Install pinned dependencies with `npm ci --prefix sdk/javascript` from the repository
root. Node.js 22 or newer is required. Native ledger validation remains authoritative.
The examples assume the importing file is beside `resource-client.mjs`; from another
directory, use its actual relative module path. This package is private and has no
configured public RPC origin. See [project status](../../docs/PROJECT_STATUS.md)
for current source/release and physical-qualification boundaries.

## Inspect an offer before signing

```js
import { inspectResourceOffer, prepareFunding } from './resource-client.mjs';

// Obtain these from your chain-pinned finalized-state reader and provider.
const disclosure = inspectResourceOffer({
  body: canonicalQuote, approval: providerApproval, program,
  chainId, genesis, payer: customerSigner.accountId, finalizedHeight,
});
```

Show the customer `maxProviderGwd`, `maxGruUnits`, `subsidyPercent`,
`trustProfile`, `independentVerificationRequired`, the resource manifest and
expiry height. The amount is the maximum provider amount; transaction fees are
separate. Bootstrap programs require zero subsidy and explicit operator-attested
terms. Do not turn maximums into promised actual charges or fiat prices.

After the customer explicitly accepts **this exact** program policy hash:

```js
const signed = prepareFunding({
  signer: customerSigner, offer: canonicalQuote, approval: providerApproval,
  program, chainId, genesis, nonce: finalizedAccountNonce, finalizedHeight,
  binding: { request_id: finalizedRequestId, generation: assignmentGeneration },
  endHeight, acceptedPolicyHash: disclosure.programPolicyHash,
});
```

For hosted service, use `{ hosted: finalizedHostedBinding }` with the native
hosted-binding schema. Fetch and verify the program, catalog, manifest, binding,
nonce and chain finality before calling this module. Offer verification alone does
not establish that a remote program object is finalized or currently authorized.
The SDK intentionally delegates full resource pricing/schema checks to native
validation. Do not enable automatic acceptance in a customer interface.

Persist the exact signed transaction and its identity before submission. Use your
existing authenticated RPC path, then reconcile certified inclusion and account
nonce. An HTTP acknowledgement is not settlement. On uncertainty, inspect chain
state instead of signing another transaction with a new nonce.

## Signing and recovery

`createSigner(seedBytes)` accepts an explicitly supplied 32-byte Ed25519 seed.
Load it using your approved custody mechanism; do not put keys in source, examples,
logs or browser storage. The signer returned by `createSigner` exposes
`prepareTransfer`, `prepareTreasury`, `prepareService`, `prepareResourceCapability`,
`prepareCall`, `prepareDeployment` and `signResourceDocument`; they are not separate
module-level exports. Native transaction types 11–14 require economics-enabled
genesis. Resource document signatures use distinct quote/evidence/telemetry domains.
For contract deployment, apply the validator
[512 KiB module and 600,000-byte transaction limits](../../src/Contracts/ContractTransactionLimits.php)
before signing; SDK structural checks alone do not guarantee chain admission.
Clear temporary key buffers under your custody policy.

`recoveryAction(action, genesis, policyVersion, fields)` creates exact recovery
payloads for the configured policy. See the
[recovery guide](../../docs/guides/RETENTION_AND_RECOVERY.md). It does not approve a
council or bypass native quorum, timelock, nonce and transaction-fee rules.

`meterRequest(socketPath, command)` uses bounded, owned private Unix IPC and rejects
unverified responses. This is an operator-side Node integration, not a public
browser API. See [metering](../../docs/guides/RESOURCE_METERING.md).

Run `node tests/Economics/signing.mjs` and
`node tests/Economics/resource-client.mjs` from the repository root. The signing
tests verify PHP/JavaScript canonical encoding and signatures without live funds.
