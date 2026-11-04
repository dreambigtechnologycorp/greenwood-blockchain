<?php

namespace Greenwood\Compute\Containerd\Service;

use Greenwood\Compute\Containerd\ContainerdClientSet;
use Greenwood\Compute\Containerd\ExecutionIo;
use Greenwood\Compute\Containerd\ValueObject\PreparedSnapshot;
use Greenwood\Contracts\ContainerdException;
use Greenwood\Contracts\ContainerdRpcException;
use Containerd\Services\Tasks\V1\CreateTaskRequest;
use Containerd\Services\Tasks\V1\StartRequest;
use Containerd\Services\Tasks\V1\WaitRequest;
use Containerd\Services\Tasks\V1\DeleteTaskRequest;
use Containerd\Services\Tasks\V1\GetRequest;
use Containerd\Services\Tasks\V1\KillRequest;
use Containerd\Services\Tasks\V1\CloseIORequest;
use Containerd\Services\Tasks\V1\ExecProcessRequest;
use Containerd\Services\Tasks\V1\ExecProcessResponse;
use Containerd\Services\Tasks\V1\CreateTaskResponse;
use Containerd\Services\Tasks\V1\StartResponse;
use Containerd\Services\Tasks\V1\WaitResponse;
use Containerd\Services\Tasks\V1\DeleteResponse;
use Containerd\Services\Tasks\V1\GetResponse;
use Containerd\Services\Tasks\V1\CloseIOResponse;
use Containerd\Types\Mount;
use Google\Protobuf\Any;
use Grpc;

/**
 * Task service wrapper for containerd
 * 
 * Provides methods for managing container tasks via the containerd Tasks API.
 * Uses containerd v2.2.2 generated gRPC API.
 */
class TaskService
{
    private ContainerdClientSet $clients;
    private const PREFIX = "gw-task-";

    public function __construct(ContainerdClientSet $clients)
    {
        $this->clients = $clients;
    }

    public function getNamespace(): string
    {
        return $this->clients->getConnection()->getNamespace();
    }

    /**
     * Generate a task ID from container ID
     * 
     * For init tasks, the task ID equals the container ID.
     * For exec processes, a unique exec_id is used.
     */
    public function generateTaskId(string $containerId): string
    {
        return $containerId;
    }

    /**
     * Create a task for a container
     * 
     * @param string $containerId The container ID
     * @param string $taskId The task ID (typically same as containerId for init task)
     * @param PreparedSnapshot $snapshot The prepared snapshot with mounts
     * @param string $stdin Path to stdin FIFO (optional, defaults to empty)
     * @param string $stdout Path to stdout FIFO (optional, defaults to empty)
     * @param string $stderr Path to stderr FIFO (optional, defaults to empty)
     * @param bool $terminal Whether the task has a terminal (optional, defaults to false)
     * 
     * @throws ContainerdRpcException If the RPC fails with non-OK status
     * @throws ContainerdException If there's a local error
     */
    public function create(
        string $containerId,
        string $taskId,
        PreparedSnapshot $snapshot,
        string $stdin = '',
        string $stdout = '',
        string $stderr = '',
        bool $terminal = false
    ): CreateTaskResponse {
        $request = new CreateTaskRequest();
        $request->setContainerId($containerId);
        $request->setRootfs($snapshot->getMounts());
        $request->setStdin($stdin);
        $request->setStdout($stdout);
        $request->setStderr($stderr);
        $request->setTerminal($terminal);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();
            [$response, $status] = $this->clients->getTasks()->Create(
                $request,
                $metadata,
                ["timeout" => 30_000_000]
            )->wait();

            if ($status->code !== Grpc\STATUS_OK) {
                throw new ContainerdRpcException(
                    "Create task failed: " . $status->details,
                    $status->code
                );
            }

            return $response;
        } catch (Grpc\Exception\RpcException $e) {
            throw new ContainerdRpcException(
                "Failed to create task: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } catch (Exception $e) {
            throw new ContainerdException(
                "Task creation failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Start a task
     * 
     * @param string $containerId The container ID
     * @param string $taskId The task/exec ID (empty string for init task)
     * 
     * @return StartResponse The response with PID
     * 
     * @throws ContainerdRpcException If the RPC fails with non-OK status
     * @throws ContainerdException If there's a local error
     */
    public function start(string $containerId, string $taskId = ''): StartResponse
    {
        $request = new StartRequest();
        $request->setContainerId($containerId);
        $request->setExecId($taskId);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();
            [$response, $status] = $this->clients->getTasks()->Start(
                $request,
                $metadata,
                ["timeout" => 30_000_000]
            )->wait();

            if ($status->code !== Grpc\STATUS_OK) {
                throw new ContainerdRpcException(
                    "Start task failed: " . $status->details,
                    $status->code
                );
            }

            return $response;
        } catch (Grpc\Exception\RpcException $e) {
            throw new ContainerdRpcException(
                "Failed to start task: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } catch (Exception $e) {
            throw new ContainerdException(
                "Task start failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Wait for a task to exit
     * 
     * @param string $containerId The container ID
     * @param string $taskId The task/exec ID (empty string for init task)
     * 
     * @return WaitResponse The response with exit status and timestamp
     * 
     * @throws ContainerdRpcException If the RPC fails with non-OK status
     * @throws ContainerdException If there's a local error
     */
    public function wait(string $containerId, string $taskId = '', int $timeoutMicros = 60_000_000): WaitResponse
    {
        $request = new WaitRequest();
        $request->setContainerId($containerId);
        $request->setExecId($taskId);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();
            [$response, $status] = $this->clients->getTasks()->Wait(
                $request,
                $metadata,
                ["timeout" => $timeoutMicros]
            )->wait();

            if ($status->code !== Grpc\STATUS_OK) {
                throw new ContainerdRpcException(
                    "Wait task failed: " . $status->details,
                    $status->code
                );
            }

            return $response;
        } catch (Grpc\Exception\RpcException $e) {
            throw new ContainerdRpcException(
                "Failed to wait for task: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } catch (Exception $e) {
            throw new ContainerdException(
                "Task wait failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Delete a task
     * 
     * @param string $containerId The container ID
     * @param string $taskId The task/exec ID (empty string for init task)
     * 
     * @return DeleteResponse The response with task info
     * 
     * @throws ContainerdRpcException If the RPC fails with non-OK status (except NOT_FOUND)
     * @throws ContainerdException If there's a local error
     */
    public function delete(string $containerId, string $taskId = ''): DeleteResponse
    {
        $request = new DeleteTaskRequest();
        $request->setContainerId($containerId);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();
            [$response, $status] = $this->clients->getTasks()->Delete(
                $request,
                $metadata,
                ["timeout" => 30_000_000]
            )->wait();

            // Delete is idempotent - success even if task doesn't exist
            if ($status->code === Grpc\STATUS_NOT_FOUND) {
                // Return a default response for idempotent behavior
                $deleteResponse = new DeleteResponse();
                $deleteResponse->setId($containerId);
                return $deleteResponse;
            }

            if ($status->code !== Grpc\STATUS_OK) {
                throw new ContainerdRpcException(
                    "Delete task failed: " . $status->details,
                    $status->code
                );
            }

            return $response;
        } catch (Grpc\Exception\RpcException $e) {
            // If already not found, treat as success
            if ($e->getCode() !== Grpc\STATUS_NOT_FOUND) {
                throw new ContainerdRpcException(
                    "Failed to delete task: " . $e->getMessage(),
                    $e->getCode(),
                    $e
                );
            }
            // Return a default response for idempotent behavior
            $deleteResponse = new DeleteResponse();
            $deleteResponse->setId($containerId);
            return $deleteResponse;
        } catch (Exception $e) {
            throw new ContainerdException(
                "Task deletion failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Send a signal to a task
     *
     * Used for graceful termination (SIGTERM), forced termination (SIGKILL),
     * and timeout enforcement. The task is NOT reaped by this call; the
     * caller must still wait()/delete() the task afterwards.
     *
     * @param string $containerId The container ID
     * @param int $signal The POSIX signal number (e.g. 15 = SIGTERM, 9 = SIGKILL)
     * @param string $taskId The task/exec ID (empty string for init task)
     * @param bool $all Whether to signal all processes in the task
     *
     * @throws ContainerdRpcException If the RPC fails with non-OK status
     * @throws ContainerdException If there's a local error
     */
    public function kill(string $containerId, int $signal, string $taskId = '', bool $all = false): void
    {
        $request = new KillRequest();
        $request->setContainerId($containerId);
        $request->setSignal($signal);
        $request->setAll($all);
        if ($taskId !== '') {
            $request->setExecId($taskId);
        }

        try {
            $metadata = $this->clients->getConnection()->getMetadata();
            [$response, $status] = $this->clients->getTasks()->Kill(
                $request,
                $metadata,
                ["timeout" => 30_000_000]
            )->wait();

            if ($status->code !== Grpc\STATUS_OK) {
                throw new ContainerdRpcException(
                    "Kill task failed: " . $status->details,
                    $status->code
                );
            }
        } catch (Grpc\Exception\RpcException $e) {
            throw new ContainerdRpcException(
                "Failed to kill task: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } catch (Exception $e) {
            throw new ContainerdException(
                "Task kill failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get task information
     * 
     * @param string $containerId The container ID
     * @param string $taskId The task/exec ID (empty string for init task)
     * 
     * @return GetResponse The response with process info
     * 
     * @throws ContainerdRpcException If the RPC fails with non-OK status
     * @throws ContainerdException If there's a local error
     */
    public function get(string $containerId, string $taskId = ''): GetResponse
    {
        $request = new GetRequest();
        $request->setContainerId($containerId);
        $request->setExecId($taskId);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();
            [$response, $status] = $this->clients->getTasks()->Get(
                $request,
                $metadata,
                ["timeout" => 30_000_000]
            )->wait();

            if ($status->code !== Grpc\STATUS_OK) {
                throw new ContainerdRpcException(
                    "Get task failed: " . $status->details,
                    $status->code
                );
            }

            return $response;
        } catch (Grpc\Exception\RpcException $e) {
            throw new ContainerdRpcException(
                "Failed to get task: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } catch (Exception $e) {
            throw new ContainerdException(
                "Task get failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Close I/O for a task
     * 
     * Note: This is NOT an "Attach" RPC. The containerd v2.2.2 API does not have
     * an Attach method. I/O is handled through the CreateTaskRequest fields
     * (stdin, stdout, stderr) or through ExecProcessRequest for exec processes.
     * 
     * @param string $containerId The container ID
     * @param string $taskId The task/exec ID (empty string for init task)
     * @param ExecutionIo $io The I/O handler
     * @param bool $stdin Whether to close stdin (optional, defaults to true)
     * 
     * @throws ContainerdRpcException If the RPC fails with non-OK status
     * @throws ContainerdException If there's a local error
     */
    public function closeIo(string $containerId, string $taskId = '', bool $stdin = true): void
    {
        $request = new CloseIORequest();
        $request->setContainerId($containerId);
        $request->setExecId($taskId);
        $request->setStdin($stdin);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();
            [$response, $status] = $this->clients->getTasks()->CloseIO(
                $request,
                $metadata,
                ["timeout" => 30_000_000]
            )->wait();

            if ($status->code !== Grpc\STATUS_OK) {
                throw new ContainerdRpcException(
                    "Close I/O failed: " . $status->details,
                    $status->code
                );
            }
        } catch (Grpc\Exception\RpcException $e) {
            throw new ContainerdRpcException(
                "Failed to close I/O: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } catch (Exception $e) {
            throw new ContainerdException(
                "Close I/O failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Execute a new process in a running container
     * 
     * Note: This is an alternative to "Attach" for running additional processes.
     * Use ExecProcessRequest instead of trying to attach to an existing task.
     * 
     * @param string $containerId The container ID
     * @param string $execId The unique exec process ID
     * @param string $stdin Path to stdin FIFO (optional)
     * @param string $stdout Path to stdout FIFO (optional)
     * @param string $stderr Path to stderr FIFO (optional)
     * @param bool $terminal Whether the process has a terminal (optional)
     * @param Any|null $spec The process spec (optional)
     * 
     * @return ExecProcessResponse The response with exec process info
     * 
     * @throws ContainerdRpcException If the RPC fails with non-OK status
     * @throws ContainerdException If there's a local error
     */
    public function execProcess(
        string $containerId,
        string $execId,
        string $stdin = '',
        string $stdout = '',
        string $stderr = '',
        bool $terminal = false,
        ?Any $spec = null
    ): ExecProcessResponse {
        $request = new ExecProcessRequest();
        $request->setContainerId($containerId);
        $request->setStdin($stdin);
        $request->setStdout($stdout);
        $request->setStderr($stderr);
        $request->setTerminal($terminal);
        $request->setExecId($execId);
        $request->setSpec($spec);

        try {
            $metadata = $this->clients->getConnection()->getMetadata();
            [$response, $status] = $this->clients->getTasks()->Exec(
                $request,
                $metadata,
                ["timeout" => 30_000_000]
            )->wait();

            if ($status->code !== Grpc\STATUS_OK) {
                throw new ContainerdRpcException(
                    "Exec process failed: " . $status->details,
                    $status->code
                );
            }

            return $response;
        } catch (Grpc\Exception\RpcException $e) {
            throw new ContainerdRpcException(
                "Failed to exec process: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } catch (Exception $e) {
            throw new ContainerdException(
                "Exec process failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }
}
