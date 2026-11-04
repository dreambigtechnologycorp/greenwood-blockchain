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
namespace Containerd\Services\Leases\V1;

/**
 * Leases service manages resources leases within the metadata store.
 */
class LeasesClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * Create creates a new lease for managing changes to metadata. A lease
     * can be used to protect objects from being removed.
     * @param \Containerd\Services\Leases\V1\CreateRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Leases\V1\CreateResponse>
     */
    public function Create(\Containerd\Services\Leases\V1\CreateRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.leases.v1.Leases/Create',
        $argument,
        ['\Containerd\Services\Leases\V1\CreateResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Delete deletes the lease and makes any unreferenced objects created
     * during the lease eligible for garbage collection if not referenced
     * or retained by other resources during the lease.
     * @param \Containerd\Services\Leases\V1\DeleteRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Google\Protobuf\GPBEmpty>
     */
    public function Delete(\Containerd\Services\Leases\V1\DeleteRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.leases.v1.Leases/Delete',
        $argument,
        ['\Google\Protobuf\GPBEmpty', 'decode'],
        $metadata, $options);
    }

    /**
     * List lists all active leases, returning the full list of
     * leases and optionally including the referenced resources.
     * @param \Containerd\Services\Leases\V1\ListRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Leases\V1\ListResponse>
     */
    public function List(\Containerd\Services\Leases\V1\ListRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.leases.v1.Leases/List',
        $argument,
        ['\Containerd\Services\Leases\V1\ListResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * AddResource references the resource by the provided lease.
     * @param \Containerd\Services\Leases\V1\AddResourceRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Google\Protobuf\GPBEmpty>
     */
    public function AddResource(\Containerd\Services\Leases\V1\AddResourceRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.leases.v1.Leases/AddResource',
        $argument,
        ['\Google\Protobuf\GPBEmpty', 'decode'],
        $metadata, $options);
    }

    /**
     * DeleteResource dereferences the resource by the provided lease.
     * @param \Containerd\Services\Leases\V1\DeleteResourceRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Google\Protobuf\GPBEmpty>
     */
    public function DeleteResource(\Containerd\Services\Leases\V1\DeleteResourceRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.leases.v1.Leases/DeleteResource',
        $argument,
        ['\Google\Protobuf\GPBEmpty', 'decode'],
        $metadata, $options);
    }

    /**
     * ListResources lists all the resources referenced by the lease.
     * @param \Containerd\Services\Leases\V1\ListResourcesRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Leases\V1\ListResourcesResponse>
     */
    public function ListResources(\Containerd\Services\Leases\V1\ListResourcesRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.leases.v1.Leases/ListResources',
        $argument,
        ['\Containerd\Services\Leases\V1\ListResourcesResponse', 'decode'],
        $metadata, $options);
    }

}
