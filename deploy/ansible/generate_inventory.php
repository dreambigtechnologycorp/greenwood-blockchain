#!/usr/bin/env php
<?php
// Retain the established Terraform -> PHP entrypoint; the shared profile validator
// is also used by release/preflight tooling. No shell interpolation of inventory.
$command = ['python3', __DIR__ . '/../..' . '/scripts/render-inventory.py'];
$process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
if (!is_resource($process)) { fwrite(STDERR, "Inventory renderer unavailable\n"); exit(1); }
exit(proc_close($process));
