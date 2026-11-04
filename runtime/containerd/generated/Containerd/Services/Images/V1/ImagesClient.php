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
namespace Containerd\Services\Images\V1;

/**
 * Images is a service that allows one to register images with containerd.
 *
 * In containerd, an image is merely the mapping of a name to a content root,
 * described by a descriptor. The behavior and state of image is purely
 * dictated by the type of the descriptor.
 *
 * From the perspective of this service, these references are mostly shallow,
 * in that the existence of the required content won't be validated until
 * required by consuming services.
 *
 * As such, this can really be considered a "metadata service".
 */
class ImagesClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * Get returns an image by name.
     * @param \Containerd\Services\Images\V1\GetImageRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Images\V1\GetImageResponse>
     */
    public function Get(\Containerd\Services\Images\V1\GetImageRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.images.v1.Images/Get',
        $argument,
        ['\Containerd\Services\Images\V1\GetImageResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * List returns a list of all images known to containerd.
     * @param \Containerd\Services\Images\V1\ListImagesRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Images\V1\ListImagesResponse>
     */
    public function List(\Containerd\Services\Images\V1\ListImagesRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.images.v1.Images/List',
        $argument,
        ['\Containerd\Services\Images\V1\ListImagesResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Create an image record in the metadata store.
     *
     * The name of the image must be unique.
     * @param \Containerd\Services\Images\V1\CreateImageRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Images\V1\CreateImageResponse>
     */
    public function Create(\Containerd\Services\Images\V1\CreateImageRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.images.v1.Images/Create',
        $argument,
        ['\Containerd\Services\Images\V1\CreateImageResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Update assigns the name to a given target image based on the provided
     * image.
     * @param \Containerd\Services\Images\V1\UpdateImageRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Containerd\Services\Images\V1\UpdateImageResponse>
     */
    public function Update(\Containerd\Services\Images\V1\UpdateImageRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.images.v1.Images/Update',
        $argument,
        ['\Containerd\Services\Images\V1\UpdateImageResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Delete deletes the image by name.
     * @param \Containerd\Services\Images\V1\DeleteImageRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Google\Protobuf\GPBEmpty>
     */
    public function Delete(\Containerd\Services\Images\V1\DeleteImageRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/containerd.services.images.v1.Images/Delete',
        $argument,
        ['\Google\Protobuf\GPBEmpty', 'decode'],
        $metadata, $options);
    }

}
