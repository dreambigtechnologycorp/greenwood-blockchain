#!/usr/bin/env bash
set -euo pipefail
umask 022
# Build local OCI archives from committed source; never pushes or installs services.
root="$(git rev-parse --show-toplevel)"
runtime="${GREENWOOD_PHP_RUNTIME:?Supply the reviewed PHP/SQLite/Sodium/FFI/Wasmtime runtime by digest}"
[[ "$runtime" =~ ^[^[:space:]]+@sha256:[a-f0-9]{64}$ ]] || { echo 'Digest-pinned runtime required' >&2; exit 1; }
platform="${GREENWOOD_SERVICE_PLATFORM:-linux/arm64}"
[[ "$platform" == linux/arm64 || "$platform" == linux/amd64 ]] || { echo 'Explicit supported platform required' >&2; exit 1; }
output="${1:?Supply a new output directory}"
test ! -e "$output" || { echo 'Output already exists' >&2; exit 1; }
git -C "$root" diff --quiet HEAD -- src system-services || { echo 'Commit and review service source before building' >&2; exit 1; }
[[ -z "$(git -C "$root" ls-files --others --exclude-standard -- src system-services)" ]] || { echo 'Review and commit new service source before building' >&2; exit 1; }
for service in registry registry-auth metering attestor metering-coordinator; do
  git -C "$root" ls-files --error-unmatch "system-services/$service/Dockerfile" >/dev/null
done
commit="$(git -C "$root" rev-parse HEAD)"
context="$(mktemp -d)"
trap 'rm -rf "$context"' EXIT
git -C "$root" archive "$commit" src system-services LICENSE NOTICE TRADEMARKS.md THIRD_PARTY_NOTICES.md LICENSES | tar -x -C "$context"
mkdir -p "$output"
if [[ -n "${GREENWOOD_BUILDX_BIN:-}" ]]; then buildx=("$GREENWOOD_BUILDX_BIN"); else buildx=(docker buildx); fi
sbom="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["sbom_generator"])' "$context/system-services/php-runtime/inputs.lock.json")"
extra=()
if [[ -n "${GREENWOOD_RUNTIME_LAYOUT:-}" ]]; then
  extra=(--build-context "greenwood-runtime=oci-layout://${GREENWOOD_RUNTIME_LAYOUT}@${runtime##*@}")
  runtime=greenwood-runtime
fi
for service in registry registry-auth metering attestor metering-coordinator; do
  "${buildx[@]}" build "${extra[@]}" --platform "$platform" --provenance=mode=max --attest "type=sbom,generator=$sbom" --label "org.opencontainers.image.revision=$commit" \
    --build-arg "GREENWOOD_PHP_RUNTIME=$runtime" \
    --build-arg "SOURCE_DATE_EPOCH=$(git -C "$root" log -1 --format=%ct)" \
    --file "$context/system-services/$service/Dockerfile" \
    --metadata-file "$output/$service.metadata.json" \
    --output "type=oci,rewrite-timestamp=true,dest=$output/$service.oci.tar" "$context"
done
python3 "$root/scripts/verify-oci-release.py" "$output" --source "$commit" --platform "$platform"
(cd "$output" && sha256sum ./*.oci.tar > SHA256SUMS)
printf '%s\n' "$commit" > "$output/SOURCE_COMMIT"
