<?php
/** Source-checkout equivalent of the optional package's Composer PSR-4 mapping. */
if(!getenv('P42_INSTALLED'))spl_autoload_register(static function(string $class):void{
    $prefix='Tenant\\Exceptions\\';
    if(!str_starts_with($class,$prefix))return;
    $path=__DIR__.'/../../packages/tenant-postgres/src/Exceptions/Public/'.substr($class,strlen($prefix)).'.php';
    if(is_file($path))require $path;
},true,true);
