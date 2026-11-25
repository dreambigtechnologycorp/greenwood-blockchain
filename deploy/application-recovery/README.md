# Application restart recovery

Use this only after protocol/provider readiness, durable state and recovery
configuration have been verified. Fresh installation and its bounded continuation
stage the timer without enabling it. The unit wants the provider service and can
start it; enabling it during pre-start storage work violates that stopped-service
boundary. [Project status](../../docs/PROJECT_STATUS.md) records the reference
installation separately from this reusable service procedure.

Starting containerd alone does not recreate application tasks lost during a host
reboot. Enable this recovery timer on each application provider as part of the
approved hosted-service activation, after its public provider identity pin and
protected funding configuration are complete. It restores an already authorized running application; it does not
create a deployment operation or repeat application registration.

`greenwood-application-recovery.timer` runs 20 seconds after boot and every 30 seconds after
the previous pass finishes. It restores provider application records that were
confirmed `RUNNING`, using their retained container, immutable image reference,
snapshot parent, lease and network allocation. It refreshes the task PID and
reattaches networking before reporting health. System registry/auth services keep
their existing systemd supervisors.

Recovery takes the same per-application lock as operator start/stop. It inspects
before every retry, including after uncertain Create/Start responses. It does not
pull images, replace containers, allocate ports, change assignments, submit chain
transactions, or restart `STOPPED`, `STOPPING`, `FAILED` or uncertain initial
`STARTING` applications. Missing backing resources, identity mismatches and
protected services require operator attention. Failures are isolated per service
and reported as `RETRY_REQUIRED` in the journal; network readiness is invalidated
when runtime recovery or health verification fails.

Install the service/timer under `/etc/systemd/system` and create a root-owned
0600 `/etc/greenwood/application-recovery.env` containing absolute paths:

```ini
GREENWOOD_RECOVERY_ROOT=/opt/approved-greenwood-release
GREENWOOD_PROVIDER_CONFIG=/etc/greenwood/provider.json
GREENWOOD_HOSTED_FUNDING_CONFIG=/etc/greenwood/hosted-funding.json
GREENWOOD_APPLICATION_NETWORK_POLICY=/path/to/existing/protected/network-policy.json
```

Use the provider's actual config with its matching `expectedPublicKey`, the approved
hosted-funding configuration and existing network policy. The fresh-install
environment currently omits `GREENWOOD_HOSTED_FUNDING_CONFIG`; complete that
protected input before enabling recovery. Do not create a
replacement policy or database. The runtime must include the recovery command,
bootstrap and updated ProviderApplication classes plus its compatible Composer
dependencies. An additive recovery bundle can link `vendor` to the pinned
installed provider release; retain that release until recovery is migrated.

Validate units with `systemd-analyze verify`, reload systemd, run the service once,
inspect its journal, and enable the timer with `systemctl enable --now
greenwood-application-recovery.timer`. The forwarding and containerd services
must be installed. This service needs host networking privileges; do not enable
PrivateNetwork or a private network namespace.

From the approved installed release, after provisioning the environment file:

```sh
sudo install -o root -g root -m 0644 deploy/application-recovery/greenwood-application-recovery.service /etc/systemd/system/
sudo install -o root -g root -m 0644 deploy/application-recovery/greenwood-application-recovery.timer /etc/systemd/system/
sudo systemd-analyze verify /etc/systemd/system/greenwood-application-recovery.service /etc/systemd/system/greenwood-application-recovery.timer
sudo systemctl daemon-reload
sudo systemctl start greenwood-application-recovery.service
sudo journalctl -u greenwood-application-recovery.service -n 50 --no-pager
sudo systemctl enable --now greenwood-application-recovery.timer
sudo systemctl list-timers greenwood-application-recovery.timer
```

Review the one-shot result before enabling the timer. HEALTHY confirms that pass;
RETRY_REQUIRED needs inspection of the named application's retained resources.
An empty result does not prove a workload was recovered. Inspect service logs
locally and redact operational details before sharing them.

The fresh-cluster playbook installs this timer through `tasks/application_recovery.yml`
with activation disabled; the shared first-start tail leaves it disabled. The task
default used by standalone maintenance can enable it, so set
`greenwood_application_recovery_activate: false` for staging-only operations.
The standalone Ansible control-plane role does not install recovery units. Include this explicit step
in standalone provider provisioning; enabling the provider or forwarding unit
alone does not enable application task restoration. The source release must
contain the command, bootstrap, restorer and compatible registry/network/inspector
classes together. Do not install only the unit files.

Tests:

```sh
php vendor/bin/phpunit --do-not-cache-result --bootstrap deploy/application-recovery/bootstrap.php tests/Unit/Provider/ProviderApplicationRestorerTest.php
```

For acceptance, confirm the same assigned digests and ingress ports, remove only
the intended application tasks under a maintenance window, and observe recovery
by the timer without an operator start. Verify backend and public HTML/assets
against existing release evidence. A task-loss test does not establish full OS
reboot acceptance. In an approved maintenance window, reboot one provider at a
time and verify timer activation, matching retained container/image identity,
fresh task PID, restored network binding, backend health and public content.
Do not reboot another node until the first has recovered. A changed PID is normal;
an unexplained digest, container ID, assignment or ingress-port change is not.

To disable recovery, stop and disable the timer and stop its oneshot service.
This does not stop application tasks. Never restore pre-recovery databases over
live state as a rollback procedure.

Hosted economic recovery now requires a pinned `expectedPublicKey` in provider configuration and protected hosted funding configuration. It reads no signing key. An expired, revoked or unavailable funded interval blocks task resume and triggers managed network cleanup. See [hosted settlement](../../docs/economics/HOSTED_SETTLEMENT_REPORT.md) and the
applicable network's acceptance evidence; local tests do not establish production qualification.
