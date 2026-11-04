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
namespace Containerd\Services\Snapshots\V1;

/**
 * Snapshot service manages snapshots
 */
class SnapshotsClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param \Containerd\Services\Snapshots\V1\PrepareSnapshotRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Snapshots\V1\PrepareSnapshotResponse>
     */
    public function Prepare(\Containerd\Services\Snapshots\V1\PrepareSnapshotRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.snapshots.v1.Snapshots/Prepare',
        $argument,
        ['\Containerd\Services\Snapshots\V1\PrepareSnapshotResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Snapshots\V1\ViewSnapshotRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Snapshots\V1\ViewSnapshotResponse>
     */
    public function View(\Containerd\Services\Snapshots\V1\ViewSnapshotRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.snapshots.v1.Snapshots/View',
        $argument,
        ['\Containerd\Services\Snapshots\V1\ViewSnapshotResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Snapshots\V1\MountsRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Snapshots\V1\MountsResponse>
     */
    public function Mounts(\Containerd\Services\Snapshots\V1\MountsRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.snapshots.v1.Snapshots/Mounts',
        $argument,
        ['\Containerd\Services\Snapshots\V1\MountsResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Snapshots\V1\CommitSnapshotRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Google\Protobuf\GPBEmpty>
     */
    public function Commit(\Containerd\Services\Snapshots\V1\CommitSnapshotRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.snapshots.v1.Snapshots/Commit',
        $argument,
        ['\Google\Protobuf\GPBEmpty', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Snapshots\V1\RemoveSnapshotRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Google\Protobuf\GPBEmpty>
     */
    public function Remove(\Containerd\Services\Snapshots\V1\RemoveSnapshotRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.snapshots.v1.Snapshots/Remove',
        $argument,
        ['\Google\Protobuf\GPBEmpty', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Snapshots\V1\StatSnapshotRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Snapshots\V1\StatSnapshotResponse>
     */
    public function Stat(\Containerd\Services\Snapshots\V1\StatSnapshotRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.snapshots.v1.Snapshots/Stat',
        $argument,
        ['\Containerd\Services\Snapshots\V1\StatSnapshotResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Snapshots\V1\UpdateSnapshotRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Snapshots\V1\UpdateSnapshotResponse>
     */
    public function Update(\Containerd\Services\Snapshots\V1\UpdateSnapshotRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.snapshots.v1.Snapshots/Update',
        $argument,
        ['\Containerd\Services\Snapshots\V1\UpdateSnapshotResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Snapshots\V1\ListSnapshotsRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\ServerStreamingCall
     */
    public function List(\Containerd\Services\Snapshots\V1\ListSnapshotsRequest $argument,
      $metadata = [], $options = []) {
        return $this->_serverStreamRequest('/containerd.services.snapshots.v1.Snapshots/List',
        $argument,
        ['\Containerd\Services\Snapshots\V1\ListSnapshotsResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Snapshots\V1\UsageRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Snapshots\V1\UsageResponse>
     */
    public function Usage(\Containerd\Services\Snapshots\V1\UsageRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.snapshots.v1.Snapshots/Usage',
        $argument,
        ['\Containerd\Services\Snapshots\V1\UsageResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Snapshots\V1\CleanupRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Google\Protobuf\GPBEmpty>
     */
    public function Cleanup(\Containerd\Services\Snapshots\V1\CleanupRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.snapshots.v1.Snapshots/Cleanup',
        $argument,
        ['\Google\Protobuf\GPBEmpty', 'decode'],
        $metadata, $options);
    }

}
