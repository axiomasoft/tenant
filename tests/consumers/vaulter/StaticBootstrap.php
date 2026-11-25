<?php

declare(strict_types=1);

require getenv('VAULTER_DEV_AUTOLOAD');
$loader=new Composer\Autoload\ClassLoader;
foreach (['Vaulter\\Tenant\\'=>'tenant/src','Vaulter\\Drive\\'=>'drive/src','Vaulter\\Media\\'=>'media/src','Vaulter\\'=>'core/src'] as $prefix=>$path) {
    $loader->addPsr4($prefix,getenv('VAULTER_CONSUMER_CHECKOUT').'/packages/'.$path);
}
$loader->register(true);
