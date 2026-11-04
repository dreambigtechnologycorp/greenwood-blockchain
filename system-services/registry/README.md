# GREENWOOD_REGISTRY_V1

Greenwood network-owned OCI registry.

This directory's Dockerfile/configuration are bootstrap image inputs, not a
verified description of the current running registry. The example bearer realm
uses a private HTTP service name; a physical deployment needs the approved issuer
endpoint and corresponding verification certificate. Do not expose that example
unchanged or infer its image tag from the digest without artifact provenance.

Bootstrap properties:

- engine: project-zot/zot, selected by the Dockerfile's immutable image digest
- base image pinned by immutable digest
- listens on TCP 5000
- data lives at /var/lib/registry
- bearer authorization is delegated to GREENWOOD_REGISTRY_AUTH_V1
- issuer public key is mounted at:
  /etc/greenwood/registry-auth/issuer-public.pem

This image contains no issuer private key.

The image is built once as a Greenwood release artifact, exported as an OCI
artifact, and preloaded onto eligible Greenwood providers.

The source-controlled system-service supervisor requires a protected definition,
immutable `imageRef`/`expectedDigest` agreement and an already-imported image.
Ansible stages definitions and units; it neither imports seed images nor starts
the registry. Preserve repositories and storage when reconciling configuration.
See [the physical handoff](../../deploy/ansible/CONTROL_PLANE_HANDOFF.md) for seed
artifact requirements, issuer alignment and separate activation acceptance.
