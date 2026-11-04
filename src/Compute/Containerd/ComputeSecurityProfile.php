<?php
declare(strict_types=1);
namespace Greenwood\Compute\Containerd;

/**
 * Frozen v1 execution security controls (Pass 4F section 47).
 *
 * Internal profile only — never on the wire. Trust class affects provider
 * eligibility, never sandbox strength: OPEN/TRUSTED/SOVEREIGN all execute
 * under this same baseline.
 *
 * V1 controls:
 *   - all five capability sets empty
 *   - noNewPrivileges = true
 *   - private pid/ipc/uts/mount/network/cgroup namespaces; host namespace
 *     sharing and privileged mode are not representable (no setters exist)
 *   - devices limited to null/zero/full/random/urandom/tty (+cgroup allowlist)
 *   - sysfs read-only; standard masked/readonly proc paths
 *   - explicit environment only (no provider-process inheritance)
 *   - seccomp: default-allow with errno denylist for high-risk syscalls
 *   - network: private namespace with no external interface ⇒ no connectivity
 *
 * Known v1 limitations (documented in docs/compute-security-v1.md):
 *   - no user namespace (container uid 1000 maps to host uid 1000)
 *   - disk bound requires operator-configured EROFS block mode; overlayfs
 *     alone has no per-workload writable-layer bound
 */
final class ComputeSecurityProfile
{
    public const VERSION = 1;

    /**
     * High-risk syscalls denied with EPERM even though all capabilities are
     * dropped: defense in depth against namespace/mount/keyring/kernel
     * interfaces that must never be reachable from a workload.
     */
    public const DENIED_SYSCALLS = [
        'mount', 'umount2', 'pivot_root', 'ptrace',
        'keyctl', 'add_key', 'request_key',
        'bpf', 'perf_event_open',
        'kexec_load', 'kexec_file_load',
        'init_module', 'finit_module', 'delete_module',
        'open_by_handle_at', 'name_to_handle_at',
        'reboot', 'swapon', 'swapoff',
        'setns', 'unshare',
        'acct', 'quotactl', 'quotactl_fd',
        'move_mount', 'open_tree', 'fsopen', 'fsconfig', 'fsmount', 'fspick',
        'mount_setattr',
    ];

    private function __construct() {}

    /** OCI seccomp section: default allow + errno denylist. */
    public static function seccomp(): array
    {
        return [
            'defaultAction' => 'SCMP_ACT_ALLOW',
            'architectures' => ['SCMP_ARCH_AARCH64', 'SCMP_ARCH_X86_64'],
            'syscalls' => [
                ['names' => self::DENIED_SYSCALLS, 'action' => 'SCMP_ACT_ERRNO'],
            ],
        ];
    }
}
