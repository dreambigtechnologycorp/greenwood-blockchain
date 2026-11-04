# Local four-validator quickstart

This guide creates a disposable local network with the checked-in command-line
tools. It is deliberately explicit: consensus finality requires three separate
validator processes, not one process configured to impersonate a network.

## Prerequisites

- PHP 8.5 with sodium and PDO SQLite enabled.
- Composer dependencies installed with `composer install`.
- Four available local TCP ports, starting at the base port you choose.

Wasmtime and containerd are not required to demonstrate basic chain startup.
They are required for contract execution and provider operation respectively.

## 1. Create disposable genesis material

From the repository root:

```bash
composer install
php bin/greenwood-cli genesis ./local-genesis 9201
```

The command writes `genesis.json` and a different Ed25519 key file for each of
the fixed validator IDs `A`, `B`, `C`, and `D`. It refuses to overwrite an
existing target directory. Treat these files as development secrets and keep
them out of source control.

## 2. Start all four validators

Run one command per terminal. Each validator needs a distinct data directory.

```bash
php bin/greenwoodd --genesis=./local-genesis/genesis.json --data-dir=./local-data/A --node=A --key=./local-genesis/keys/A.key
php bin/greenwoodd --genesis=./local-genesis/genesis.json --data-dir=./local-data/B --node=B --key=./local-genesis/keys/B.key
php bin/greenwoodd --genesis=./local-genesis/genesis.json --data-dir=./local-data/C --node=C --key=./local-genesis/keys/C.key
php bin/greenwoodd --genesis=./local-genesis/genesis.json --data-dir=./local-data/D --node=D --key=./local-genesis/keys/D.key
```

At startup each process emits a small JSON record containing its node ID and
current height. The genesis helper configures peers as
`http://127.0.0.1:9201` through `:9204`.

The daemon holds an exclusive `node.lock` in its data directory. A second
process using the same directory is rejected, which prevents concurrent local
writers from corrupting a node's state.

## 3. Inspect finalized state

The public listener exposes the versioned `/v1` API. With validator A running:

```bash
php bin/greenwood chain status --rpc=http://127.0.0.1:9201
php bin/greenwood chain finality --rpc=http://127.0.0.1:9201
curl http://127.0.0.1:9201/v1/health
```

Use the [`greenwood` CLI](../cli.md) for client workflows. It reads the API;
it does not inspect `state.sqlite` or block files directly.

## 4. Create and sign a transfer locally

`greenwood-cli` signs an envelope locally and prints the JSON payload accepted
by `greenwood tx submit`:

```bash
# Replace RECIPIENT_HEX with a 64-character lowercase account identifier.
# Nonce 0 applies only to A's first transfer on this disposable genesis.
php bin/greenwood-cli transfer-sign \
  ./local-genesis/genesis.json ./local-genesis/keys/A.key \
  RECIPIENT_HEX 1 0 > transfer.json

php bin/greenwood tx submit transfer.json --rpc=http://127.0.0.1:9201
```

Admission is not finality. Use the returned transaction hash with
`greenwood tx status <hash>` and wait for `FINALIZED`. The public API never
claims that a mempool admission has changed canonical state.

For later transfers, read the sender's finalized nonce first. If submission is
uncertain, reconcile the hash and account state before signing again; do not
repeat the example with nonce zero or a guessed new nonce.

## 5. Stop and replay safely

Send `SIGINT` or `SIGTERM` to the daemons. To check an existing data directory
without starting consensus, use:

```bash
php bin/greenwoodd \
  --genesis=./local-genesis/genesis.json \
  --data-dir=./local-data/A \
  --node=A \
  --key=./local-genesis/keys/A.key \
  --replay
```

The command prints the recovered height, block hash, and state root. Do not
delete a validator's signing journal to "repair" it: a missing journal in an
already initialized validator directory is an intentional startup failure.

## Next steps

- [Transactions](../concepts/transactions.md) and [public RPC v1](../rpc-v1.md)
- [Contract development](../contracts/quickstart.md)
- [Provider operations](../provider-operations.md)
- [State recovery](../architecture/recovery.md)

---

<!-- docs-nav -->
**Continue reading:** [Documentation hub](../README.md) · [Getting started](overview.md) · [Local quickstart](quickstart.md)
<!-- /docs-nav -->
