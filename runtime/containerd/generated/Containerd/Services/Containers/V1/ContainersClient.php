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
namespace Containerd\Services\Containers\V1;

/**
 * Containers provides metadata storage for containers used in the execution
 * service.
 *
 * The objects here provide an state-independent view of containers for use in
 * management and resource pinning. From that perspective, containers do not
 * have a "state" but rather this is the set of resources that will be
 * considered in use by the container.
 *
 * From the perspective of the execution service, these objects represent the
 * base parameters for creating a container process.
 *
 * In general, when looking to add fields for this type, first ask yourself
 * whether or not the function of the field has to do with runtime execution or
 * is invariant of the runtime state of the container. If it has to do with
 * runtime, or changes as the "container" is started and stops, it probably
 * doesn't belong on this object.
 */
class ContainersClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param \Containerd\Services\Containers\V1\GetContainerRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Containers\V1\GetContainerResponse>
     */
    public function Get(\Containerd\Services\Containers\V1\GetContainerRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.containers.v1.Containers/Get',
        $argument,
        ['\Containerd\Services\Containers\V1\GetContainerResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Containers\V1\ListContainersRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Containers\V1\ListContainersResponse>
     */
    public function List(\Containerd\Services\Containers\V1\ListContainersRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.containers.v1.Containers/List',
        $argument,
        ['\Containerd\Services\Containers\V1\ListContainersResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Containers\V1\ListContainersRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\ServerStreamingCall
     */
    public function ListStream(\Containerd\Services\Containers\V1\ListContainersRequest $argument,
      $metadata = [], $options = []) {
        return $this->_serverStreamRequest('/containerd.services.containers.v1.Containers/ListStream',
        $argument,
        ['\Containerd\Services\Containers\V1\ListContainerMessage', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Containers\V1\CreateContainerRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Containers\V1\CreateContainerResponse>
     */
    public function Create(\Containerd\Services\Containers\V1\CreateContainerRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.containers.v1.Containers/Create',
        $argument,
        ['\Containerd\Services\Containers\V1\CreateContainerResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Containers\V1\UpdateContainerRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Containers\V1\UpdateContainerResponse>
     */
    public function Update(\Containerd\Services\Containers\V1\UpdateContainerRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.containers.v1.Containers/Update',
        $argument,
        ['\Containerd\Services\Containers\V1\UpdateContainerResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Containers\V1\DeleteContainerRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Google\Protobuf\GPBEmpty>
     */
    public function Delete(\Containerd\Services\Containers\V1\DeleteContainerRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.containers.v1.Containers/Delete',
        $argument,
        ['\Google\Protobuf\GPBEmpty', 'decode'],
        $metadata, $options);
    }

}
