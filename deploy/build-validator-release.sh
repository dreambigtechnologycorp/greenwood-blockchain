#!/usr/bin/env bash
set -euo pipefail
# Greenwood Validator Release Builder
# Target platform: Linux ARM64 (Ubuntu 24.04, PHP 8.5)

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Deployment tooling lives outside the source checkout; the source workspace
# is explicit, never inferred from the script location when overridden.
PROJECT_ROOT="${GREENWOOD_SOURCE_DIR:-$(cd "${SCRIPT_DIR}/.." && pwd)}"
BUILD_DIR="${PROJECT_ROOT}/build/release"
STAGING_DIR="${BUILD_DIR}/staging"
ARTIFACT_DIR="${BUILD_DIR}/artifacts"

RELEASE_VERSION="${RELEASE_VERSION:-1.0.0}"
RELEASE_TIMESTAMP="$(date -u +%Y%m%d%H%M%S)"
RELEASE_ID="${RELEASE_VERSION}-${RELEASE_TIMESTAMP}"

verify_platform() {
    local os="$(uname -s)"
    local arch="$(uname -m)"
    
    if [[ "${os}" != "Linux" ]]; then
        echo "ERROR: Release build must run on Linux" >&2
        exit 1
    fi
    
    if [[ "${arch}" != "aarch64" ]]; then
        echo "ERROR: Release build must run on ARM64" >&2
        exit 1
    fi
    
    echo "[OK] Platform verified: ${os} ${arch}"
}

verify_php() {
    local php_version
    php_version="$(php -r 'echo phpversion();' 2>/dev/null)"
    
    if [[ -z "${php_version}" ]]; then
        echo "ERROR: PHP is not installed or not executable" >&2
        exit 1
    fi
    
    if ! php -r 'exit(PHP_VERSION_ID === 80512 ? 0 : 1);'; then
        echo "ERROR: PHP 8.5.12 is required for release builds; found ${php_version}" >&2
        exit 1
    fi
    
    echo "[OK] PHP version: ${php_version}"
}

verify_extensions() {
    local required_extensions=("grpc" "sodium" "pdo_sqlite" "ffi" "pcntl" "posix")
    local missing=()
    
    for ext in "${required_extensions[@]}"; do
        if ! php -r "exit(extension_loaded('${ext}') ? 0 : 1);" 2>/dev/null; then
            missing+=("${ext}")
        fi
    done

    if [[ "${#missing[@]}" -gt 0 ]]; then
        echo "ERROR: Missing required PHP extensions: ${missing[*]}" >&2
        exit 1
    fi

    echo "[OK] Required extensions available"
}

verify_lockfile() {
    local lockfile="${PROJECT_ROOT}/composer.lock"
    
    if [[ ! -f "${lockfile}" ]]; then
        echo "ERROR: composer.lock not found" >&2
        exit 1
    fi
    
    echo "[OK] composer.lock is valid"
}

setup_staging() {
    rm -rf "${STAGING_DIR}"
    mkdir -p "${STAGING_DIR}"
    echo "[OK] Staging directory ready"
}

copy_runtime() {
    # Package only the pinned commit, excluding untracked or ignored experiments.
    local source_sha
    source_sha="$(git -C "${PROJECT_ROOT}" rev-parse HEAD)"
    git -C "${PROJECT_ROOT}" -c core.filemode=false diff --quiet HEAD -- src bin runtime composer.json composer.lock || {
        echo "ERROR: Release source contains tracked local changes" >&2
        exit 1
    }
    git -C "${PROJECT_ROOT}" archive "${source_sha}" src bin runtime system-services deploy/application-recovery composer.json composer.lock LICENSE NOTICE TRADEMARKS.md LICENSES THIRD_PARTY_NOTICES.md | tar -x -C "${STAGING_DIR}"
    printf '{"source_sha":"%s"}\n' "${source_sha}" > "${STAGING_DIR}/release-source.json"
    echo "[OK] Runtime files copied"
}

install_dependencies() {
    cd "${STAGING_DIR}"
    composer install --no-dev --optimize-autoloader --classmap-authoritative --no-scripts
    
    echo "[OK] Composer dependencies installed"
}

cleanup_development() {
    rm -rf "${STAGING_DIR}/tests/" 2>/dev/null || true
    rm -rf "${STAGING_DIR}/docs/" 2>/dev/null || true
    rm -rf "${STAGING_DIR}/scripts/" 2>/dev/null || true
    rm -rf "${STAGING_DIR}/.git/" 2>/dev/null || true
    echo "[OK] Development artifacts removed"
}

create_archive() {
    local artifact_name="greenwood-validator-${RELEASE_ID}.tar.gz"
    local artifact_path="${ARTIFACT_DIR}/${artifact_name}"
    
    mkdir -p "${ARTIFACT_DIR}"
    cd "${STAGING_DIR}"
    tar -czf "${artifact_path}" .
    
    local sha256="$(shasum -a 256 "${artifact_path}" | cut -d' ' -f1)"
    echo "${sha256}  ${artifact_name}" > "${ARTIFACT_DIR}/${artifact_name}.sha256"

    # Canonical application tree digest (matches the gate run on /opt/greenwood
    # excluding deployment-managed wasmtime-* runtimes). The controller records
    # this so the physical-network gates compare live trees to the release.
    local tree_digest
    tree_digest="$(compute_tree_digest "${STAGING_DIR}")"

    echo "[OK] Release archive created: ${artifact_path}"
    echo "[OK] SHA256: ${sha256}"
    echo "[OK] Tree SHA256: ${tree_digest}"
}

compute_tree_digest() {
    local dir="$1"
    (
        cd "${dir}"
        find . -name 'wasmtime-*' -prune -o -type f -print0 | sort -z | xargs -0 shasum -a 256 | shasum -a 256 | awk '{print $1}'
    )
}

main() {
    echo "Greenwood Validator Release Builder"
    echo "Release ID: ${RELEASE_ID}"
    verify_platform
    verify_php
    verify_extensions
    verify_lockfile
    setup_staging
    copy_runtime
    install_dependencies
    cleanup_development
    create_archive
    echo "Build complete!"
}

main "$@"
