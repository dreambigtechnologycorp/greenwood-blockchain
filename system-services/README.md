# Greenwood System Services

These are network-owned bootstrap services.

They are not ordinary user applications and are not hosted by Greenwood Studio.

Initial services:

- GREENWOOD_REGISTRY_V1
- GREENWOOD_REGISTRY_AUTH_V1

Dockerfiles are source definitions.

The authoritative deployment artifact is the immutable OCI image digest produced
during a Greenwood network release. Bootstrap OCI artifacts are distributed with
the node/provider release and imported locally before system-service scheduling.

Ordinary application workloads are subsequently distributed through
GREENWOOD_REGISTRY_V1.

## Source and Installation Boundary

`runtime/supervise.php` uses the existing containerd system-service runtime with
protected root-owned definitions and immutable digest verification. It does not
generate identities, build/pull seed images or silently adopt a different task.
An uncertain start requires runtime reconciliation; source staging is not acceptance.

The deployment controller is a separate PHP control-plane service in this directory,
not an application-registry owner and not a replacement OCI runtime. Its signed
developer API drives approved provider operations and edge admission.

Use [current functionality](../docs/CURRENT_FUNCTIONALITY.md) and the
[physical handoff](../deploy/ansible/CONTROL_PLANE_HANDOFF.md), not historical H8
one-off scripts, as the current installation guide.
