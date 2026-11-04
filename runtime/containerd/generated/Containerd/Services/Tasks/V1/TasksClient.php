<?php
// GENERATED CODE -- DO NOT EDIT!

// Original file comments:
//
// Copyright The containerd Authors.
//
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
//
// http://www.apache.org/licenses/LICENSE-2.0
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
namespace Containerd\Services\Tasks\V1;

/**
 */
class TasksClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * Create a task.
     * @param \Containerd\Services\Tasks\V1\CreateTaskRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Tasks\V1\CreateTaskResponse>
     */
    public function Create(\Containerd\Services\Tasks\V1\CreateTaskRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.tasks.v1.Tasks/Create',
        $argument,
        ['\Containerd\Services\Tasks\V1\CreateTaskResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Start a process.
     * @param \Containerd\Services\Tasks\V1\StartRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Tasks\V1\StartResponse>
     */
    public function Start(\Containerd\Services\Tasks\V1\StartRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.tasks.v1.Tasks/Start',
        $argument,
        ['\Containerd\Services\Tasks\V1\StartResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Delete a task and on disk state.
     * @param \Containerd\Services\Tasks\V1\DeleteTaskRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Tasks\V1\DeleteResponse>
     */
    public function Delete(\Containerd\Services\Tasks\V1\DeleteTaskRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.tasks.v1.Tasks/Delete',
        $argument,
        ['\Containerd\Services\Tasks\V1\DeleteResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Tasks\V1\DeleteProcessRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Tasks\V1\DeleteResponse>
     */
    public function DeleteProcess(\Containerd\Services\Tasks\V1\DeleteProcessRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.tasks.v1.Tasks/DeleteProcess',
        $argument,
        ['\Containerd\Services\Tasks\V1\DeleteResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Tasks\V1\GetRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Tasks\V1\GetResponse>
     */
    public function Get(\Containerd\Services\Tasks\V1\GetRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.tasks.v1.Tasks/Get',
        $argument,
        ['\Containerd\Services\Tasks\V1\GetResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Tasks\V1\ListTasksRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Tasks\V1\ListTasksResponse>
     */
    public function List(\Containerd\Services\Tasks\V1\ListTasksRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.tasks.v1.Tasks/List',
        $argument,
        ['\Containerd\Services\Tasks\V1\ListTasksResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Kill a task or process.
     * @param \Containerd\Services\Tasks\V1\KillRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Google\Protobuf\GPBEmpty>
     */
    public function Kill(\Containerd\Services\Tasks\V1\KillRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.tasks.v1.Tasks/Kill',
        $argument,
        ['\Google\Protobuf\GPBEmpty', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Tasks\V1\ExecProcessRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Google\Protobuf\GPBEmpty>
     */
    public function Exec(\Containerd\Services\Tasks\V1\ExecProcessRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.tasks.v1.Tasks/Exec',
        $argument,
        ['\Google\Protobuf\GPBEmpty', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Tasks\V1\ResizePtyRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Google\Protobuf\GPBEmpty>
     */
    public function ResizePty(\Containerd\Services\Tasks\V1\ResizePtyRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.tasks.v1.Tasks/ResizePty',
        $argument,
        ['\Google\Protobuf\GPBEmpty', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Tasks\V1\CloseIORequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Google\Protobuf\GPBEmpty>
     */
    public function CloseIO(\Containerd\Services\Tasks\V1\CloseIORequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.tasks.v1.Tasks/CloseIO',
        $argument,
        ['\Google\Protobuf\GPBEmpty', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Tasks\V1\PauseTaskRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Google\Protobuf\GPBEmpty>
     */
    public function Pause(\Containerd\Services\Tasks\V1\PauseTaskRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.tasks.v1.Tasks/Pause',
        $argument,
        ['\Google\Protobuf\GPBEmpty', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Tasks\V1\ResumeTaskRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Google\Protobuf\GPBEmpty>
     */
    public function Resume(\Containerd\Services\Tasks\V1\ResumeTaskRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.tasks.v1.Tasks/Resume',
        $argument,
        ['\Google\Protobuf\GPBEmpty', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Tasks\V1\ListPidsRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Tasks\V1\ListPidsResponse>
     */
    public function ListPids(\Containerd\Services\Tasks\V1\ListPidsRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.tasks.v1.Tasks/ListPids',
        $argument,
        ['\Containerd\Services\Tasks\V1\ListPidsResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Tasks\V1\CheckpointTaskRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Tasks\V1\CheckpointTaskResponse>
     */
    public function Checkpoint(\Containerd\Services\Tasks\V1\CheckpointTaskRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.tasks.v1.Tasks/Checkpoint',
        $argument,
        ['\Containerd\Services\Tasks\V1\CheckpointTaskResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Tasks\V1\UpdateTaskRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Google\Protobuf\GPBEmpty>
     */
    public function Update(\Containerd\Services\Tasks\V1\UpdateTaskRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.tasks.v1.Tasks/Update',
        $argument,
        ['\Google\Protobuf\GPBEmpty', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Tasks\V1\MetricsRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Tasks\V1\MetricsResponse>
     */
    public function Metrics(\Containerd\Services\Tasks\V1\MetricsRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.tasks.v1.Tasks/Metrics',
        $argument,
        ['\Containerd\Services\Tasks\V1\MetricsResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Tasks\V1\WaitRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Tasks\V1\WaitResponse>
     */
    public function Wait(\Containerd\Services\Tasks\V1\WaitRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.tasks.v1.Tasks/Wait',
        $argument,
        ['\Containerd\Services\Tasks\V1\WaitResponse', 'decode'],
        $metadata, $options);
    }

}
