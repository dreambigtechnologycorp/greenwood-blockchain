#!/bin/sh
set -eu
# No network install. Supply the locally approved AssemblyScript compiler.
: "${GREENWOOD_ASSEMBLYSCRIPT_ROOT:?Set GREENWOOD_ASSEMBLYSCRIPT_ROOT to AssemblyScript 0.28.20 package directory}"
: "${1:?Usage: build.sh /absolute/output.wasm}"
node -e 'if(require(process.argv[1]).version!=="0.28.20")process.exit(1)' "$GREENWOOD_ASSEMBLYSCRIPT_ROOT/package.json"
node "$GREENWOOD_ASSEMBLYSCRIPT_ROOT/bin/asc.js" "$(dirname "$0")/ApplicationRegistry.ts" --outFile "$1" --runtime stub --exportStart _start --initialMemory 1 --maximumMemory 1 --optimizeLevel 3 --shrinkLevel 2
