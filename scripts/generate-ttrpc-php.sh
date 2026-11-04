#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Use the pinned, unmodified upstream schemas. Well-known protobuf imports
# must be available to protoc (set PROTOBUF_INCLUDE if not installed globally).
includes=()
if [[ -n "${PROTOBUF_INCLUDE:-}" ]]; then
    includes+=("-I${PROTOBUF_INCLUDE}")
fi
protoc -I runtime/containerd/proto/ttrpc-v1.2.7 "${includes[@]}" \
    --php_out=runtime/containerd/generated \
    runtime/containerd/proto/ttrpc-v1.2.7/request.proto \
    runtime/containerd/proto/ttrpc-v1.2.7/proto/status.proto
protoc -I runtime/containerd/proto/googleapis "${includes[@]}" \
    --php_out=runtime/containerd/generated \
    runtime/containerd/proto/googleapis/google/rpc/status.proto
php composer.phar dump-autoload --no-scripts
