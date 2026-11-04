<?php
declare(strict_types=1);
namespace Greenwood\Tests\Unit\Compute;

use Greenwood\Compute\Containerd\{ComputeSecurityProfile, OciSpecBuilder};
use PHPUnit\Framework\TestCase;

/**
 * Pass 4F spec-level security regression (Darwin-safe; Linux runtime
 * acceptance runs separately on greenwood-dev).
 */
final class OciSpecSecurityTest extends TestCase
{
    private function spec(): array
    {
        return json_decode(OciSpecBuilder::forShellCommand('rootfs', 'echo hi')->buildJson(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testAllFiveCapabilitySetsEmpty(): void
    {
        $caps = $this->spec()['process']['capabilities'];
        foreach (['bounding', 'effective', 'permitted', 'inheritable', 'ambient'] as $set) {
            self::assertArrayHasKey($set, $caps);
            self::assertSame([], $caps[$set], "$set must be empty");
        }
    }

    public function testNoNewPrivilegesAndUnprivilegedUser(): void
    {
        $process = $this->spec()['process'];
        self::assertTrue($process['noNewPrivileges']);
        self::assertSame(['uid' => 1000, 'gid' => 1000], $process['user']);
    }

    public function testPrivateNamespacesIncludingCgroupAndNetwork(): void
    {
        $types = array_column($this->spec()['linux']['namespaces'], 'type');
        foreach (['pid', 'ipc', 'uts', 'mount', 'network', 'cgroup'] as $ns) {
            self::assertContains($ns, $types);
        }
        foreach ($this->spec()['linux']['namespaces'] as $ns) {
            self::assertArrayNotHasKey('path', $ns, 'Host namespace path must never appear');
        }
    }

    public function testDeviceAllowlistExact(): void
    {
        $paths = array_column($this->spec()['linux']['devices'], 'path');
        sort($paths);
        self::assertSame(['/dev/full', '/dev/null', '/dev/random', '/dev/tty', '/dev/urandom', '/dev/zero'], $paths);
        foreach (['/dev/sda', '/dev/kvm', '/dev/mem', '/dev/net/tun'] as $forbidden) {
            self::assertNotContains($forbidden, $paths);
        }
    }

    public function testNoHostBindMountsAndSysfsReadOnly(): void
    {
        foreach ($this->spec()['mounts'] as $mount) {
            $source = $mount['source'];
            self::assertFalse(str_starts_with($source, '/') && !in_array($mount['type'], ['bind'], true),
                'Host path mount: '.$source);
            if ($mount['destination'] === '/sys') {
                self::assertContains('ro', $mount['options']);
            }
        }
        $destinations = array_column($this->spec()['mounts'], 'destination');
        foreach (['/run/containerd', '/run/greenwood', '/host', '/etc', '/root', '/home'] as $forbidden) {
            self::assertNotContains($forbidden, $destinations);
        }
    }

    public function testMaskedAndReadonlyPathsPresent(): void
    {
        $linux = $this->spec()['linux'];
        foreach (['/proc/kcore', '/proc/keys', '/proc/sched_debug', '/sys/firmware'] as $masked) {
            self::assertContains($masked, $linux['maskedPaths']);
        }
        foreach (['/proc/sys', '/proc/sysrq-trigger'] as $ro) {
            self::assertContains($ro, $linux['readonlyPaths']);
        }
    }

    public function testSeccompDenylist(): void
    {
        $seccomp = $this->spec()['linux']['seccomp'];
        self::assertSame('SCMP_ACT_ALLOW', $seccomp['defaultAction']);
        $denied = $seccomp['syscalls'][0]['names'];
        self::assertSame('SCMP_ACT_ERRNO', $seccomp['syscalls'][0]['action']);
        foreach (['mount', 'umount2', 'ptrace', 'keyctl', 'bpf', 'kexec_load', 'init_module',
                  'open_by_handle_at', 'reboot', 'setns', 'unshare', 'swapon'] as $syscall) {
            self::assertContains($syscall, $denied);
        }
        // Normal process creation must remain allowed.
        self::assertNotContains('clone3', $denied);
        self::assertNotContains('execve', $denied);
    }

    public function testEnvironmentIsExplicitOnly(): void
    {
        $env = $this->spec()['process']['env'];
        foreach ($env as $entry) {
            $name = explode('=', $entry, 2)[0];
            self::assertNotContains($name, ['GREENWOOD_WASMTIME_LIB', 'SSH_AUTH_SOCK', 'AWS_SECRET_ACCESS_KEY']);
        }
    }

    public function testResourceControlsPresent(): void
    {
        $resources = $this->spec()['linux']['resources'];
        self::assertArrayHasKey('limit', $resources['memory']);
        self::assertArrayHasKey('limit', $resources['pids']);
        self::assertArrayHasKey('shares', $resources['cpu']);
    }

    public function testProfileIsVersionedAndDenylistStable(): void
    {
        self::assertSame(1, ComputeSecurityProfile::VERSION);
        self::assertSame(ComputeSecurityProfile::DENIED_SYSCALLS, ComputeSecurityProfile::seccomp()['syscalls'][0]['names']);
    }
}
