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
namespace Containerd\Services\Namespaces\V1;

/**
 * Namespaces provides the ability to manipulate containerd namespaces.
 *
 * All objects in the system are required to be a member of a namespace. If a
 * namespace is deleted, all objects, including containers, images and
 * snapshots, will be deleted, as well.
 *
 * Unless otherwise noted, operations in containerd apply only to the namespace
 * supplied per request.
 *
 * I hope this goes without saying, but namespaces are themselves NOT
 * namespaced.
 */
class NamespacesClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param \Containerd\Services\Namespaces\V1\GetNamespaceRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Namespaces\V1\GetNamespaceResponse>
     */
    public function Get(\Containerd\Services\Namespaces\V1\GetNamespaceRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.namespaces.v1.Namespaces/Get',
        $argument,
        ['\Containerd\Services\Namespaces\V1\GetNamespaceResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Namespaces\V1\ListNamespacesRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Namespaces\V1\ListNamespacesResponse>
     */
    public function List(\Containerd\Services\Namespaces\V1\ListNamespacesRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.namespaces.v1.Namespaces/List',
        $argument,
        ['\Containerd\Services\Namespaces\V1\ListNamespacesResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Namespaces\V1\CreateNamespaceRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Namespaces\V1\CreateNamespaceResponse>
     */
    public function Create(\Containerd\Services\Namespaces\V1\CreateNamespaceRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.namespaces.v1.Namespaces/Create',
        $argument,
        ['\Containerd\Services\Namespaces\V1\CreateNamespaceResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Namespaces\V1\UpdateNamespaceRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Namespaces\V1\UpdateNamespaceResponse>
     */
    public function Update(\Containerd\Services\Namespaces\V1\UpdateNamespaceRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.namespaces.v1.Namespaces/Update',
        $argument,
        ['\Containerd\Services\Namespaces\V1\UpdateNamespaceResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Containerd\Services\Namespaces\V1\DeleteNamespaceRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Google\Protobuf\GPBEmpty>
     */
    public function Delete(\Containerd\Services\Namespaces\V1\DeleteNamespaceRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.namespaces.v1.Namespaces/Delete',
        $argument,
        ['\Google\Protobuf\GPBEmpty', 'decode'],
        $metadata, $options);
    }

}
