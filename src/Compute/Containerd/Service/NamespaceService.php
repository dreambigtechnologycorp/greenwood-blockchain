<?php

namespace Greenwood\Compute\Containerd\Service;

use Greenwood\Compute\Containerd\ContainerdClientSet;

/**
 * Namespace service wrapper
 */
class NamespaceService
{
    private ContainerdClientSet $clients;

    public function __construct(ContainerdClientSet $clients)
    {
        $this->clients = $clients;
    }

    public function getNamespace(): string
    {
        return $this->clients->getConnection()->getNamespace();
    }
}

