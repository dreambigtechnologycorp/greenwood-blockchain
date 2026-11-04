#!/usr/bin/env bash
set -e

# Directory setup
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
PROTO_DIR="$PROJECT_DIR/runtime/containerd/proto"
GENERATED_DIR="$PROJECT_DIR/runtime/containerd/generated"

# Version info
CONTAINERD_REPO="$PROTO_DIR/containerd-repo"
# Check if git repo exists and get version info
if [ -d "$CONTAINERD_REPO" ]; then
    cd "$CONTAINERD_REPO"
    CONTAINERD_COMMIT="$(git rev-parse HEAD 2>/dev/null || echo "unknown")"
    CONTAINERD_TAG="$(git describe --tags 2>/dev/null || echo "v2.2.2")"
    cd - > /dev/null
fi

echo "Generating containerd gRPC PHP bindings..."
echo "Containerd version: $CONTAINERD_TAG"
echo "Containerd commit: $CONTAINERD_COMMIT"
echo "Generated to: $GENERATED_DIR"

# Clean and recreate generated directory
rm -rf "$GENERATED_DIR"
mkdir -p "$GENERATED_DIR"

# Get the include paths
CONTAINERD_PROTO_ROOT="$CONTAINERD_REPO"
CONTAINERD_VENDOR_ROOT="$CONTAINERD_REPO/vendor"
PHP_PROTOBUF_SRC="$PROJECT_DIR/vendor/google/protobuf/src"

# Find the protobuf plugin
GRPC_PHP_PLUGIN="$(which grpc_php_plugin 2>/dev/null || find /usr /usr/local -name grpc_php_plugin 2>/dev/null | head -1)"
if [ -z "$GRPC_PHP_PLUGIN" ]; then
    echo "ERROR: grpc_php_plugin not found"
    exit 1
fi
echo "Using grpc_php_plugin: $GRPC_PHP_PLUGIN"

# Change to proto root so relative paths work correctly
cd "$CONTAINERD_PROTO_ROOT"

# All containerd proto files for native lifecycle
echo "Generating containerd proto files..."

# First generate all types protos (these are imported by service protos)
# The import path in service protos is: github.com/containerd/containerd/api/types/mount.proto
# This resolves to: vendor/github.com/containerd/containerd/api/types/mount.proto (via -I vendor)
TYPES_TO_GENERATE=(
    "github.com/containerd/containerd/api/types/mount.proto"
    "github.com/containerd/containerd/api/types/descriptor.proto"
    "github.com/containerd/containerd/api/types/event.proto"
    "github.com/containerd/containerd/api/types/fieldpath.proto"
    "github.com/containerd/containerd/api/types/introspection.proto"
    "github.com/containerd/containerd/api/types/metrics.proto"
    "github.com/containerd/containerd/api/types/platform.proto"
    "github.com/containerd/containerd/api/types/sandbox.proto"
    "github.com/containerd/containerd/api/types/task/task.proto"
)

for proto_file in "${TYPES_TO_GENERATE[@]}"; do
    echo "Generating types: $proto_file"
    protoc         -I .         -I "$CONTAINERD_VENDOR_ROOT"         -I "$PHP_PROTOBUF_SRC"         --php_out="$GENERATED_DIR"         "$proto_file"
done

# All containerd service proto files for native lifecycle
echo "Generating containerd services..."
CONTAINERD_SERVICES=(
    "api/services/version/v1/version.proto"
    "api/services/namespaces/v1/namespace.proto"
    "api/services/snapshots/v1/snapshots.proto"
    "api/services/content/v1/content.proto"
    "api/services/images/v1/images.proto"
    "api/services/containers/v1/containers.proto"
    "api/services/tasks/v1/tasks.proto"
)

for proto_file in "${CONTAINERD_SERVICES[@]}"; do
    echo "Generating: $proto_file"
    protoc         -I .         -I "$CONTAINERD_VENDOR_ROOT"         -I "$PHP_PROTOBUF_SRC"         --plugin=protoc-gen-grpc="$GRPC_PHP_PLUGIN"         --php_out="$GENERATED_DIR"         --grpc_out="$GENERATED_DIR"         "$proto_file"
done

echo "Done."
