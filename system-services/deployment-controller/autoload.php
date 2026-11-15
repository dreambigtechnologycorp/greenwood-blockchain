<?php
declare(strict_types=1);
if(is_file(dirname(__DIR__,2).'/vendor/autoload.php'))require_once dirname(__DIR__,2).'/vendor/autoload.php';
else require_once dirname(__DIR__,2).'/scripts/economics-bootstrap.php';
spl_autoload_register(static function(string $class):void {
    $prefix='Greenwood\\DeploymentController\\';
    if(str_starts_with($class,$prefix)) { $file=__DIR__.'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php'; if(is_file($file))require_once $file; }
});
