<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
use Greenwood\DeploymentController\Assignment\{PhysicalAssignmentServices, PolicyInstaller};
if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 0) throw new RuntimeException('Root Linux required');
umask(0077);
$config = PhysicalAssignmentServices::config(getenv('GREENWOOD_ASSIGNMENT_CONFIG') ?: '');
$bytes = stream_get_contents(STDIN, 1048577);
echo json_encode(PolicyInstaller::install($config, $bytes), JSON_THROW_ON_ERROR), "\n";
