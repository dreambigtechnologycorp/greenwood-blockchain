# Third-Party Notices

The Apache License 2.0 applies to original Greenwood code. This does not
relicense third-party components. Retain upstream notices when redistributing
them. See [NOTICE](NOTICE) for Greenwood attribution and [LICENSE](LICENSE)
for the original-code license.

| Material | Provenance | License |
| --- | --- | --- |
| containerd API schemas and generated PHP bindings under `runtime/containerd/` | containerd v2.2.2, commit `301b2dac98f15c27117da5c8af12118a041a31d9`; see `runtime/containerd/VERSION` | Apache-2.0 |
| TTRPC schemas and generated bindings | containerd/ttrpc v1.2.7; checksums in `runtime/containerd/proto/ttrpc-v1.2.7/README.md` | Apache-2.0 |
| Google RPC status schema and generated bindings | googleapis commit `efc9e8f560a5f1b9b08b62823bfa7955c656cb74` | Apache-2.0 |

The [Apache license](LICENSES/Apache-2.0.txt) and
[containerd notice](LICENSES/containerd-NOTICE.txt) are included. Existing
copyright headers in copied schemas remain authoritative.

Composer dependencies are identified by `composer.lock` and retain their own
licenses. Generate an installed dependency inventory with `composer licenses
--format=json` after `composer install`; it includes development dependencies
unless excluded. The source archive does not bundle `vendor/`.

Wasmtime, containerd, runc, EROFS utilities, PHP, Apache and operating-system
packages are external runtime dependencies, not bundled binaries in this source
release. Runtime images and binary distributions need their own dependency and
notice inventory. The containerd Git submodule and its vendored dependencies
are also not embedded in the source-only archive; initialize the pinned submodule
in a Git checkout when regeneration is required.

The locked AssemblyScript build-tool dependencies retain their upstream
Apache-2.0 licenses. They are development tools and are not bundled in the
source archive.
