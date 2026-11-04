<?php
declare(strict_types=1);

namespace Greenwood\Compute\Containerd;

use RuntimeException;
use InvalidArgumentException;

/**
 * Handles FIFO creation and I/O for task execution
 */
class ExecutionIo
{
    private ?string $stdoutPath = null;
    private ?string $stderrPath = null;
    /** @var mixed stream descriptor for the stdout FIFO (O_RDWR, non-blocking) */
    private mixed $stdoutFd = null;
    /** @var mixed stream descriptor for the stderr FIFO (O_RDWR, non-blocking) */
    private mixed $stderrFd = null;
    private string $tempDir;

    // Bounded retained output (draining itself is NEVER bounded)
    private string $stdoutBuffer = "";
    private string $stderrBuffer = "";
    private int $stdoutObserved = 0;
    private int $stderrObserved = 0;
    private bool $stdoutTruncated = false;
    private bool $stderrTruncated = false;
    private int $maxOutputBytes;
    private \HashContext $stdoutHash;
    private \HashContext $stderrHash;

    private const STDOUT_FNAME = "stdout.fifo";
    private const STDERR_FNAME = "stderr.fifo";
    private const DEFAULT_MAX_OUTPUT_BYTES = 1024 * 1024; // 1MB retained per stream

    public function __construct(?string $tempDir = null, ?int $maxOutputBytes = null)
    {
        $this->tempDir = $tempDir ?? sys_get_temp_dir();
        $this->maxOutputBytes = $maxOutputBytes ?? self::DEFAULT_MAX_OUTPUT_BYTES;
        if ($this->maxOutputBytes < 0) throw new \InvalidArgumentException('Output retention must be nonnegative');
        $this->stdoutHash = hash_init('sha256');
        $this->stderrHash = hash_init('sha256');
    }

    /**
     * Create FIFOs for stdout and stderr
     */
    public function create(): void
    {
        $this->stdoutPath = $this->tempDir . "/" . self::STDOUT_FNAME . "_" . uniqid("gw_", true);
        $this->stderrPath = $this->tempDir . "/" . self::STDERR_FNAME . "_" . uniqid("gw_", true);

        // Create FIFOs (named pipes)
        if (!posix_mkfifo($this->stdoutPath, 0600)) {
            throw new RuntimeException("Failed to create stdout FIFO: {$this->stdoutPath}");
        }

        if (!posix_mkfifo($this->stderrPath, 0600)) {
            @unlink($this->stdoutPath);
            throw new RuntimeException("Failed to create stderr FIFO: {$this->stderrPath}");
        }
    }

    /**
     * Open non-blocking read ends for both FIFOs.
     *
     * This MUST be called before Tasks.Create. runc opens the stdio FIFOs
     * for writing during task creation; with no reader present, that open
     * blocks forever and Tasks.Create deadlocks until the gRPC deadline.
     * Holding an O_RDWR non-blocking descriptor (the same approach as
     * containerd's cio.FIFOSet) lets the writer open immediately and keeps
     * output buffered in the pipe until read.
     */
    public function openReaders(): void
    {
        if ($this->stdoutPath === null || $this->stderrPath === null) {
            throw new RuntimeException("FIFOs not created yet. Call create() first.");
        }

        $fd = @fopen($this->stdoutPath, "r+");
        if ($fd === false) {
            throw new RuntimeException("Failed to open stdout FIFO for reading: {$this->stdoutPath}");
        }
        stream_set_blocking($fd, false);
        $this->stdoutFd = $fd;

        $fd = @fopen($this->stderrPath, "r+");
        if ($fd === false) {
            fclose($this->stdoutFd);
            $this->stdoutFd = null;
            throw new RuntimeException("Failed to open stderr FIFO for reading: {$this->stderrPath}");
        }
        stream_set_blocking($fd, false);
        $this->stderrFd = $fd;
    }

    /**
     * Get stdout FIFO path
     */
    public function getStdoutPath(): string
    {
        if ($this->stdoutPath === null) {
            throw new RuntimeException("FIFOs not created yet. Call create() first.");
        }
        return $this->stdoutPath;
    }

    /**
     * Get stderr FIFO path
     */
    public function getStderrPath(): string
    {
        if ($this->stderrPath === null) {
            throw new RuntimeException("FIFOs not created yet. Call create() first.");
        }
        return $this->stderrPath;
    }

    /**
     * Drain any currently-available bytes from both FIFOs.
     *
     * MUST be called periodically while the task runs: a workload emitting
     * more than the pipe capacity blocks in write() until Greenwood drains.
     * Retention is bounded per stream, but draining is NOT — once the
     * retention limit is reached, further bytes are hashed, counted and discarded
     * (stream marked truncated) so the workload can never deadlock on a
     * full pipe.
     *
     * @return bool true if any bytes were drained
     */
    public function drainAvailable(): bool
    {
        $drained = false;

        foreach (['stdout' => $this->stdoutFd, 'stderr' => $this->stderrFd] as $which => $fd) {
            if ($fd === null) {
                continue;
            }
            while (true) {
                $chunk = @fread($fd, 8192);
                if ($chunk === false || $chunk === "") {
                    break;
                }
                $drained = true;
                $this->ingest($which, $chunk);
            }
        }

        return $drained;
    }

    /**
     * Retain (up to the per-stream limit) or count-and-discard a chunk.
     */
    private function ingest(string $which, string $chunk): void
    {
        if ($which === 'stdout') {
            $this->stdoutObserved = \Greenwood\Compute\Runtime\OutputCommitment::addBytes($this->stdoutObserved, strlen($chunk));
            hash_update($this->stdoutHash, $chunk);
            $this->stdoutTruncated = $this->stdoutObserved > $this->maxOutputBytes;
            if (strlen($this->stdoutBuffer) < $this->maxOutputBytes) {
                $this->stdoutBuffer = substr(
                    $this->stdoutBuffer . $chunk,
                    0,
                    $this->maxOutputBytes
                );
            } else {
                $this->stdoutTruncated = true;
            }
            return;
        }

        $this->stderrObserved = \Greenwood\Compute\Runtime\OutputCommitment::addBytes($this->stderrObserved, strlen($chunk));
        hash_update($this->stderrHash, $chunk);
        $this->stderrTruncated = $this->stderrObserved > $this->maxOutputBytes;
        if (strlen($this->stderrBuffer) < $this->maxOutputBytes) {
            $this->stderrBuffer = substr(
                $this->stderrBuffer . $chunk,
                0,
                $this->maxOutputBytes
            );
        } else {
            $this->stderrTruncated = true;
        }
    }

    /**
     * Retained stdout (drains any pending bytes first). Bounded by the
     * configured per-stream limit; see isStdoutTruncated().
     */
    public function readStdout(): string
    {
        $this->drainAvailable();
        return $this->stdoutBuffer;
    }

    /**
     * Retained stderr (drains any pending bytes first). Bounded by the
     * configured per-stream limit; see isStderrTruncated().
     */
    public function readStderr(): string
    {
        $this->drainAvailable();
        return $this->stderrBuffer;
    }

    /** Total stdout bytes observed, including discarded overflow. */
    public function getStdoutBytes(): int
    {
        return $this->stdoutObserved;
    }

    /** Total stderr bytes observed, including discarded overflow. */
    public function getStderrBytes(): int
    {
        return $this->stderrObserved;
    }

    /** Snapshot hashes without consuming the live drain contexts. Raw 32-byte values. */
    public function getFullStdoutHash(): string { return hash_final(hash_copy($this->stdoutHash), true); }
    public function getFullStderrHash(): string { return hash_final(hash_copy($this->stderrHash), true); }
    public function getOutputDigest(): string
    {
        return \Greenwood\Compute\Runtime\OutputCommitment::digest($this->stdoutObserved, $this->getFullStdoutHash(), $this->stderrObserved, $this->getFullStderrHash());
    }

    public function isStdoutTruncated(): bool
    {
        return $this->stdoutTruncated;
    }

    public function isStderrTruncated(): bool
    {
        return $this->stderrTruncated;
    }

    /**
     * Cleanup FIFOs
     */
    public function cleanup(): void
    {
        if ($this->stdoutFd !== null) {
            @fclose($this->stdoutFd);
            $this->stdoutFd = null;
        }
        if ($this->stderrFd !== null) {
            @fclose($this->stderrFd);
            $this->stderrFd = null;
        }
        if ($this->stdoutPath !== null && file_exists($this->stdoutPath)) {
            @unlink($this->stdoutPath);
        }
        if ($this->stderrPath !== null && file_exists($this->stderrPath)) {
            @unlink($this->stderrPath);
        }
    }

    /**
     * Check if stdout FIFO was created
     */
    public function hasStdout(): bool
    {
        return $this->stdoutPath !== null && file_exists($this->stdoutPath);
    }

    /**
     * Check if stderr FIFO was created
     */
    public function hasStderr(): bool
    {
        return $this->stderrPath !== null && file_exists($this->stderrPath);
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
