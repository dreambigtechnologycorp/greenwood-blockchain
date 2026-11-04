<?php
declare(strict_types=1);
// One authoritative acceptance suite; failures propagate as a nonzero exit code.
$process=proc_open([PHP_BINARY,'-d','ffi.enable=true',__DIR__.'/../vendor/bin/phpunit','--bootstrap',__DIR__.'/../vendor/autoload.php',__DIR__.'/../tests/Contracts'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);
if (!is_resource($process)) throw new RuntimeException('Cannot start 3A acceptance suite');
exit(proc_close($process));
