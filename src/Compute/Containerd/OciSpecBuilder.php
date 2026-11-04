<?php

namespace Greenwood\Compute\Containerd;

use Google\Protobuf\Any;
use RuntimeException;

/**
 * Builds OCI runtime spec for containerd task execution
 */
class OciSpecBuilder
{
    private const DEFAULT_RUNTIME = "io.containerd.runc.v2";
    private const DEFAULT_SNAPSHOTTER = "overlayfs";

    private string $rootfsPath;
    private array $command;
    private array $environment;
    private string $workingDirectory = "/";
    private array $mounts = [];
    private int $memoryLimitBytes = 256 * 1024 * 1024; // 256MB default
    private int $cpuShares = 1024;
    private ?int $cpuQuotaMicros = null;   // hard CPU ceiling (CFS quota); null = unset
    private ?int $cpuPeriodMicros = null;  // CFS period for the quota
    private int $pidsLimit = 100;
    private bool $privileged = false;
    private bool $hostNetwork = false;
    private bool $hostPid = false;
    private bool $hostIpc = false;
    private ?string $user = "1000:1000";

    public function __construct(string $rootfsPath)
    {
        $this->rootfsPath = $rootfsPath;
        $this->command = ["/bin/ash", "-c", "echo hello"];
        $this->environment = ["PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"];
    }

    /**
     * Set the command to execute
     */
    public function setCommand(array $command): self
    {
        $this->command = $command;
        return $this;
    }

    /**
     * Set environment variables
     */
    public function setEnvironment(array $environment): self
    {
        $this->environment = $environment;
        return $this;
    }

    public function setWorkingDirectory(string $workingDirectory): self
    {
        if (
            $workingDirectory === ''
            || !str_starts_with($workingDirectory, '/')
            || str_contains($workingDirectory, "\0")
        ) {
            throw new \InvalidArgumentException(
                'OCI working directory must be an absolute path'
            );
        }

        $this->workingDirectory = $workingDirectory;

        return $this;
    }

    /**
     * Set memory limit in bytes
     */
    public function setMemoryLimit(int $bytes): self
    {
        $this->memoryLimitBytes = $bytes;
        return $this;
    }

    /**
     * Set CPU shares
     */
    public function setCpuShares(int $shares): self
    {
        $this->cpuShares = $shares;
        return $this;
    }

    /**
     * Set a hard CPU ceiling via CFS quota/period (microseconds).
     *
     * cpu.shares is only a relative weight; quota/period is the hard bound.
     * Example: quota 50000 / period 100000 ≈ 0.5 CPU.
     */
    public function setCpuQuota(int $quotaMicros, int $periodMicros): self
    {
        $this->cpuQuotaMicros = $quotaMicros;
        $this->cpuPeriodMicros = $periodMicros;
        return $this;
    }

    /**
     * Set PID limit
     */
    public function setPidsLimit(int $limit): self
    {
        $this->pidsLimit = $limit;
        return $this;
    }

    /**
     * Set user (uid:gid format)
     */
    public function setUser(string $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function setHostNetwork(bool $enabled = true): self
    {
        $this->hostNetwork = $enabled;
        return $this;
    }

    /**
     * Add a mount
     */
    public function addMount(array $mount): self
    {
        $this->mounts[] = $mount;
        return $this;
    }

    /**
     * Build the OCI spec as JSON string
     */
    public function buildJson(): string
    {
        $spec = $this->buildSpec();
        return json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build the OCI spec as Any protobuf with JSON content
     */
    public function build(): Any
    {
        $spec = $this->buildSpec();
        $json = json_encode($spec, JSON_UNESCAPED_SLASHES);
        
        $any = new Any();
        $any->setValue($json);
        $any->setTypeUrl("types.containerd.io/opencontainers/runtime-spec/1/Spec");
        
        return $any;
    }

    /**
     * Build the OCI spec array structure
     */
    private function buildSpec(): array
    {
        return [
            "schemaVersion" => 2,
            "ociVersion" => "1.0.2",
            "process" => [
                "user" => $this->buildUser(),
                "args" => $this->command,
                "env" => $this->environment,
                "cwd" => $this->workingDirectory,
                "capabilities" => $this->buildCapabilities(),
                "rlimits" => [
                    ["type" => "RLIMIT_NOFILE", "hard" => 1024, "soft" => 1024]
                ],
                "noNewPrivileges" => true,
            ],
            "root" => [
                "path" => $this->rootfsPath,
                "readonly" => false,
            ],
            "hostname" => "greenwood-exec",
            "linux" => [
                "namespaces" => $this->buildNamespaces(),
                "resources" => $this->buildResources(),
                "devices" => $this->buildDevices(),
                "maskedPaths" => [
                    "/proc/acpi",
                    "/proc/asound",
                    "/proc/kcore",
                    "/proc/keys",
                    "/proc/latency_stats",
                    "/proc/timer_list",
                    "/proc/timer_stats",
                    "/proc/sched_debug",
                    "/sys/firmware",
                    "/sys/devices/virtual/powercap",
                ],
                "readonlyPaths" => [
                    "/proc/bus",
                    "/proc/fs",
                    "/proc/irq",
                    "/proc/sys",
                    "/proc/sysrq-trigger",
                ],
                // Pass 4F: default-allow seccomp with high-risk syscall denylist.
                "seccomp" => ComputeSecurityProfile::seccomp(),
            ],
            "mounts" => $this->buildMounts(),
        ];
    }

    private function buildUser(): array
    {
        if ($this->user === null) {
            return ["uid" => 0, "gid" => 0];
        }
        
        $parts = explode(":", $this->user);
        if (count($parts) !== 2) {
            throw new RuntimeException("Invalid user format. Expected uid:gid");
        }
        
        return [
            "uid" => (int)$parts[0],
            "gid" => (int)$parts[1],
        ];
    }

    private function buildCapabilities(): array
    {
        // Pass 4F: all five OCI capability sets explicitly empty.
        return [
            "bounding" => [],
            "effective" => [],
            "permitted" => [],
            "inheritable" => [],
            "ambient" => [],
        ];
    }

    private function buildNamespaces(): array
    {
        $namespaces = [
            ["type" => "pid"],
            ["type" => "ipc"],
            ["type" => "uts"],
            ["type" => "mount"],
            ["type" => "cgroup"], // Pass 4F: hide host cgroup hierarchy
        ];

        if (!$this->hostNetwork) {
            $namespaces[] = ["type" => "network"];
        }

        return $namespaces;
    }

    private function buildResources(): array
    {
        $cpu = ["shares" => $this->cpuShares];
        if ($this->cpuQuotaMicros !== null && $this->cpuPeriodMicros !== null) {
            $cpu["quota"] = $this->cpuQuotaMicros;
            $cpu["period"] = $this->cpuPeriodMicros;
        }

        return [
            "memory" => [
                "limit" => $this->memoryLimitBytes,
            ],
            "cpu" => $cpu,
            "pids" => [
                "limit" => $this->pidsLimit,
            ],
            "devices" => $this->buildDeviceCgroupRules(),
        ];
    }

    private function buildDeviceCgroupRules(): array
    {
        return [
            [
                "allow" => true,
                "type" => "c",
                "major" => 1,
                "minor" => 3,
                "access" => "rwm",
            ],
            [
                "allow" => true,
                "type" => "c",
                "major" => 1,
                "minor" => 8,
                "access" => "rwm",
            ],
            [
                "allow" => true,
                "type" => "c",
                "major" => 1,
                "minor" => 7,
                "access" => "rwm",
            ],
            [
                "allow" => true,
                "type" => "c",
                "major" => 5,
                "minor" => 0,
                "access" => "rwm",
            ],
            [
                "allow" => true,
                "type" => "c",
                "major" => 1,
                "minor" => 5,
                "access" => "rwm",
            ],
            [
                "allow" => true,
                "type" => "c",
                "major" => 1,
                "minor" => 9,
                "access" => "rwm",
            ],
        ];
    }

    private function buildDevices(): array
    {
        return [
            [
                "type" => "c",
                "major" => 1,
                "minor" => 3,
                "path" => "/dev/null",
            ],
            [
                "type" => "c",
                "major" => 1,
                "minor" => 8,
                "path" => "/dev/random",
            ],
            [
                "type" => "c",
                "major" => 1,
                "minor" => 7,
                "path" => "/dev/full",
            ],
            [
                "type" => "c",
                "major" => 5,
                "minor" => 0,
                "path" => "/dev/tty",
            ],
            [
                "type" => "c",
                "major" => 1,
                "minor" => 5,
                "path" => "/dev/zero",
            ],
            [
                "type" => "c",
                "major" => 1,
                "minor" => 9,
                "path" => "/dev/urandom",
            ],
        ];
    }

    private function buildMounts(): array
    {
        $mounts = [
            // proc filesystem
            [
                "destination" => "/proc",
                "type" => "proc",
                "source" => "proc",
                "options" => ["nosuid", "noexec", "nodev"],
            ],
            // tmpfs for /dev
            [
                "destination" => "/dev",
                "type" => "tmpfs",
                "source" => "tmpfs",
                "options" => ["nosuid", "strictatime", "mode=755", "size=65536k"],
            ],
            // devpts for /dev/pts
            [
                "destination" => "/dev/pts",
                "type" => "devpts",
                "source" => "devpts",
                "options" => ["nosuid", "noexec", "newinstance", "ptmxmode=0666", "mode=0620", "gid=5"],
            ],
            // tmpfs for /dev/shm
            [
                "destination" => "/dev/shm",
                "type" => "tmpfs",
                "source" => "shm",
                "options" => ["nosuid", "noexec", "nodev", "mode=1777", "size=65536k"],
            ],
            // mqueue for /dev/mqueue
            [
                "destination" => "/dev/mqueue",
                "type" => "mqueue",
                "source" => "mqueue",
                "options" => ["nosuid", "noexec", "nodev"],
            ],
            // sysfs for /sys
            [
                "destination" => "/sys",
                "type" => "sysfs",
                "source" => "sysfs",
                "options" => ["nosuid", "noexec", "nodev", "ro"],
            ],
        ];

        // Add any additional mounts
        foreach ($this->mounts as $mount) {
            $mounts[] = $mount;
        }

        return $mounts;
    }

    /**
     * Create a default spec for /bin/sh execution
     */
    public static function forShellCommand(string $rootfsPath, string $command = "echo hello"): self
    {
        // $command is the actual command to run (not a full shell command line)
        // e.g., "echo hello" not "/bin/ash -c echo hello"
        // The method wraps it in ["/bin/ash", "-c", ...] to execute via shell
        return (new self($rootfsPath))
            ->setCommand(["/bin/ash", "-c", $command])
            ->setEnvironment([
                "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
                "HOME=/root",
                "TERM=xterm",
            ]);
    }
}
