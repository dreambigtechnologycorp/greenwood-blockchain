<?php
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';
// Additive installations can share the installed release's dependency tree.
foreach (['Registry', 'Network', 'RuntimeInspector', 'Restorer'] as $class) {
    require_once __DIR__ . '/../../src/Provider/ProviderApplication' . $class . '.php';
}
