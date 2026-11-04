#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
if($argc!==2)throw new InvalidArgumentException('Usage: init-db.php /private/existing/directory/operations.sqlite');
new Greenwood\DeploymentController\State\SqliteOperationStore($argv[1]);
echo "Operation store initialized\n";
