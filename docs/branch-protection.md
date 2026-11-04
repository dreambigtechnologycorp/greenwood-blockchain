# Protecting main

Use the protections supported by the repository's ownership, visibility and plan.
GitHub Free supports protected branches in public repositories; the current
private repository requires a supporting paid plan for enforcement. A saved rule
is not evidence that GitHub is enforcing it. Verify enforcement after changing
visibility or plan.

For the current solo-maintainer workflow, protect `main` with:

- Pull requests required, with no mandatory approving review count.
- Required checks `Source policy and packaging` and `PHP unit checks`, from
  GitHub Actions, with the branch required to be up to date.
- All review conversations resolved before merging.
- Administrator bypass disabled.
- Force pushes and branch deletion disabled.

Self-approval does not satisfy an independent review requirement. When a second
trusted maintainer is available, require one approval and dismiss stale approvals.
On personal repositories, separate push allowlists may be unavailable; requiring
PRs without bypass prevents direct updates by accounts with write access. Review
collaborator access separately and grant write access only where needed.

The `CI` workflow runs existing PHP unit, packaging and independent vector checks
on pushes and pull requests with read-only permissions and without repository
secrets. It does not run deployment commands, live containerd jobs or bare-metal
tests. Native contract, integration and physical acceptance results remain
separate from these required checks.

After enabling protection, use a harmless PR to verify that failed checks and
unresolved conversations block merging. Confirm through GitHub's settings or API
that force pushes, deletion and administrator bypass are disabled. Do not test
protection by rewriting or deleting the real `main` branch.

See [GitHub's protected branch documentation](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches).
