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
namespace Containerd\Services\Content\V1;

/**
 * Content provides access to a content addressable storage system.
 */
class ContentClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * Info returns information about a committed object.
     *
     * This call can be used for getting the size of content and checking for
     * existence.
     * @param \Containerd\Services\Content\V1\InfoRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Content\V1\InfoResponse>
     */
    public function Info(\Containerd\Services\Content\V1\InfoRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.content.v1.Content/Info',
        $argument,
        ['\Containerd\Services\Content\V1\InfoResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Update updates content metadata.
     *
     * This call can be used to manage the mutable content labels. The
     * immutable metadata such as digest, size, and committed at cannot
     * be updated.
     * @param \Containerd\Services\Content\V1\UpdateRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Content\V1\UpdateResponse>
     */
    public function Update(\Containerd\Services\Content\V1\UpdateRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.content.v1.Content/Update',
        $argument,
        ['\Containerd\Services\Content\V1\UpdateResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * List streams the entire set of content as Info objects and closes the
     * stream.
     *
     * Typically, this will yield a large response, chunked into messages.
     * Clients should make provisions to ensure they can handle the entire data
     * set.
     * @param \Containerd\Services\Content\V1\ListContentRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\ServerStreamingCall
     */
    public function List(\Containerd\Services\Content\V1\ListContentRequest $argument,
      $metadata = [], $options = []) {
        return $this->_serverStreamRequest('/containerd.services.content.v1.Content/List',
        $argument,
        ['\Containerd\Services\Content\V1\ListContentResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Delete will delete the referenced object.
     * @param \Containerd\Services\Content\V1\DeleteContentRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Google\Protobuf\GPBEmpty>
     */
    public function Delete(\Containerd\Services\Content\V1\DeleteContentRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.content.v1.Content/Delete',
        $argument,
        ['\Google\Protobuf\GPBEmpty', 'decode'],
        $metadata, $options);
    }

    /**
     * Read allows one to read an object based on the offset into the content.
     *
     * The requested data may be returned in one or more messages.
     * @param \Containerd\Services\Content\V1\ReadContentRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\ServerStreamingCall
     */
    public function Read(\Containerd\Services\Content\V1\ReadContentRequest $argument,
      $metadata = [], $options = []) {
        return $this->_serverStreamRequest('/containerd.services.content.v1.Content/Read',
        $argument,
        ['\Containerd\Services\Content\V1\ReadContentResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Status returns the status for a single reference.
     * @param \Containerd\Services\Content\V1\StatusRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Content\V1\StatusResponse>
     */
    public function Status(\Containerd\Services\Content\V1\StatusRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.content.v1.Content/Status',
        $argument,
        ['\Containerd\Services\Content\V1\StatusResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * ListStatuses returns the status of ongoing object ingestions, started via
     * Write.
     *
     * Only those matching the regular expression will be provided in the
     * response. If the provided regular expression is empty, all ingestions
     * will be provided.
     * @param \Containerd\Services\Content\V1\ListStatusesRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Content\V1\ListStatusesResponse>
     */
    public function ListStatuses(\Containerd\Services\Content\V1\ListStatusesRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.content.v1.Content/ListStatuses',
        $argument,
        ['\Containerd\Services\Content\V1\ListStatusesResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Write begins or resumes writes to a resource identified by a unique ref.
     * Only one active stream may exist at a time for each ref.
     *
     * Once a write stream has started, it may only write to a single ref, thus
     * once a stream is started, the ref may be omitted on subsequent writes.
     *
     * For any write transaction represented by a ref, only a single write may
     * be made to a given offset. If overlapping writes occur, it is an error.
     * Writes should be sequential and implementations may throw an error if
     * this is required.
     *
     * If expected_digest is set and already part of the content store, the
     * write will fail.
     *
     * When completed, the commit flag should be set to true. If expected size
     * or digest is set, the content will be validated against those values.
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\BidiStreamingCall
     */
    public function Write($metadata = [], $options = []) {
        return $this->_bidiRequest('/containerd.services.content.v1.Content/Write',
        ['\Containerd\Services\Content\V1\WriteContentResponse','decode'],
        $metadata, $options);
    }

    /**
     * Abort cancels the ongoing write named in the request. Any resources
     * associated with the write will be collected.
     * @param \Containerd\Services\Content\V1\AbortRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Google\Protobuf\GPBEmpty>
     */
    public function Abort(\Containerd\Services\Content\V1\AbortRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.content.v1.Content/Abort',
        $argument,
        ['\Google\Protobuf\GPBEmpty', 'decode'],
        $metadata, $options);
    }

}
