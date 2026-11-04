#!/bin/sh
set -eu
: "${GREENWOOD_ASSEMBLYSCRIPT_ROOT:?Set the approved AssemblyScript 0.28.20 package directory}"
: "${1:?Output WASM path required}"
node -e 'if(require(process.argv[1]).version!=="0.28.20")process.exit(1)' "$GREENWOOD_ASSEMBLYSCRIPT_ROOT/package.json"
node "$GREENWOOD_ASSEMBLYSCRIPT_ROOT/bin/asc.js" "$(dirname "$0")/ActiveRegistryV1.ts" --outFile "$1" --runtime stub --exportStart _start --initialMemory 1 --maximumMemory 1 --optimizeLevel 0 --shrinkLevel 0
binaryen_root="$(dirname "$GREENWOOD_ASSEMBLYSCRIPT_ROOT")/binaryen"
node -e 'if(require(process.argv[1]).version!=="131.0.0-nightly.20260721")process.exit(1)' "$binaryen_root/package.json"
"$binaryen_root/bin/wasm-opt" "$1" --remove-unused-module-elements -o "$1"
python3 - "$1" <<'PY'
import hashlib,pathlib,sys
actual=hashlib.sha256(pathlib.Path(sys.argv[1]).read_bytes()).hexdigest()
if actual!='86780772cd897989848f8fc48acadc41dbf2a3b8893b9da24d77d9fcb44c8e70':
    raise SystemExit('Active registry reproducibility mismatch: '+actual)
PY
