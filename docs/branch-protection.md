# Protecting main

This is a recommended repository policy, not a live audit of GitHub settings.
Verify the rules actually enforced for the repository before relying on them.
A saved configuration or a green local test is not evidence of enforcement.

For a solo-maintainer repository, protect `main` with:

- Pull requests required, with no mandatory approving review count.
- Required checks `Source policy and packaging` and `PHP unit checks`, from
  GitHub Actions, with the branch required to be up to date.
- All review conversations resolved before merging.
- Administrator bypass disabled.
- Force pushes and branch deletion disabled.

Self-approval does not satisfy an independent review requirement. When a second
trusted maintainer is available, require one approval and dismiss stale approvals.
Review collaborator access separately and grant write access only where needed.
Confirm the chosen protection or ruleset covers direct pushes and bypass actors.

The `CI` workflow runs the complete PHPUnit `source,contracts` suites, packaging
and independent vector checks on pull requests and pushes to `main`, with
read-only permissions and without repository secrets. Feature-branch pushes
are covered by their PR run, avoiding duplicate jobs. The existing required-check
name `PHP unit checks` is retained; it now includes integration, consensus, RPC,
CLI, operator, compute and Wasmtime-backed contract tests. CI verifies the pinned
Wasmtime archive checksum and installs its shared library and matching headers.
Deployment commands, the separately configured live-containerd `native` suite
and physical acceptance remain outside these required checks.

After enabling protection, use a harmless PR to verify that failed checks and
unresolved conversations block merging. Confirm through GitHub's settings or API
that force pushes, deletion and administrator bypass are disabled. Do not test
protection by rewriting or deleting the real `main` branch.

See [GitHub's protected branch documentation](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches).
