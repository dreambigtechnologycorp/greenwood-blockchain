<?php
declare(strict_types=1);
// Present in both OCI build contexts and the committed validator release.
// Native services load installed Composer dependencies separately when required.
spl_autoload_register(static function(string $class):void {
    $prefix='Greenwood\\';
    if(!str_starts_with($class,$prefix))return;
    $path=dirname(__DIR__,2).'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($path))require $path;
});
