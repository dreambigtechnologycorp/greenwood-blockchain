<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use Greenwood\SystemServices\RegistryAuth\Database;
$root=sys_get_temp_dir().'/gw-registry-db-'.bin2hex(random_bytes(8));
mkdir($root,0700);
try {
    $db=Database::open($root.'/auth.sqlite');
    clearstatcache();
    if ((fileperms($root.'/auth.sqlite')&0077)!==0) throw new RuntimeException('Database exposed');
    unset($db);
    symlink($root.'/auth.sqlite',$root.'/linked.sqlite');
    try { Database::open($root.'/linked.sqlite'); throw new LogicException('Symlink accepted'); }
    catch (RuntimeException) {}
    chmod($root.'/auth.sqlite',0644);clearstatcache();
    try { Database::open($root.'/auth.sqlite'); throw new LogicException('Public database accepted'); }
    catch (RuntimeException) {}
    echo "Registry database: private creation, symlink and permission rejection PASS\n";
} finally {
    foreach(glob($root.'/*') as $file) unlink($file);
    rmdir($root);
}
