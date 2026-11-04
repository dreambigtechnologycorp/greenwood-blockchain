# Source Release Procedure

Greenwood's PHP distribution is an experimental source release, not a certified
physical deployment. The Python CLI and application images are separate artifacts.

## Prepare

1. Review the intended commit on `main`, lockfiles, generated-code provenance,
   [third-party notices](../THIRD_PARTY_NOTICES.md) and [changelog](../CHANGELOG.md).
2. Run the applicable [tests](testing/overview.md). Record platform limitations
   and physical acceptance separately; do not imply unexecuted checks passed.
3. Scan the complete Git history for credentials before making a private
   repository public. Ignoring a key today does not remove it from old commits.
   Revoke exposed credentials before coordinating any necessary history repair.
4. Enable GitHub private vulnerability reporting and verify maintainers can
   receive reports. Confirm the [security policy](../SECURITY.md) matches reality.
5. Choose a version, finalize its changelog entry, and review license attribution.
   Create an annotated, preferably signed, tag bound to the approved commit.
   Never move an existing published tag.

## Fresh public history

When starting a new public repository, copy only reviewed source files into a new
checkout. Do not copy the old `.git` directory, local checkpoint refs, ignored
runtime state, keys, dependency directories or previous distributions. Keep the
containerd submodule pinned at its reviewed revision rather than copying its
nested repository history. The frozen signing material in test vectors is public
test data and must never be reused for an operational identity.

Before the initial commit, run:

```sh
python3 scripts/check-public-source.py
python3 -m unittest discover -s tests/Release
```

The source checker checks tracked and untracked nonignored working files for
forbidden paths and common credential formats, including binary signing keys.
It reports filenames without printing matches. It does not inspect old history
or establish the absence of every possible secret. Run the PHP suites described
in the testing guide before publishing; native infrastructure checks are separate.

## Package

From a clean checkout, using the selected tag or full commit SHA:

```sh
python3 scripts/package-source.py <tag-or-commit> --output-dir dist/source
```

The command reads committed Git objects, not working-directory files. It refuses
tracked secret/state filenames, excludes submodule contents, and writes a
commit-named `.tar.gz`, a JSON manifest and `SHA256SUMS` to a new directory.
It does not tag, sign, upload or change repository visibility. Filename checks
are a guard, not a complete secret scan. The manifest records the source commit,
submodule revisions and Composer lock checksum. No runtime credentials or OCI
images are part of this archive.

Extract the archive into a new directory, run `composer install` against its
lockfile, and exercise the supported build/test entrypoints. Regenerating
bindings requires a Git checkout with pinned submodules, not this archive alone.

## Publish

Publish the immutable tag, archive, manifest, checksums and release notes together.
Sign checksums using the maintainer's established release-signing identity; do
not create a substitute identity just to label a release signed. State the exact
runtime requirements and experimental status prominently. Keep private acceptance
records outside the published repository and archive.

Source checks do not prove clean-cluster installation, safe mainnet operation or
application deployment. Report those outcomes only after their own acceptance.
