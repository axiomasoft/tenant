<?php

declare(strict_types=1);

if (!getenv('TENANT_LAB_MANIFEST') || !is_file(getenv('TENANT_LAB_MANIFEST'))) throw new RuntimeException('Missing isolated TENANT_LAB_MANIFEST; run lab setup');
foreach (['APP_ENV'=>'testing','DB_DATABASE'=>'tenant_guard_test','MEDIA_DISK'=>'media-test'] as $key=>$value) { $_SERVER[$key]=$_ENV[$key]=$value; putenv($key.'='.$value); }
define('TENANT_SOURCE_ROOT', dirname(__DIR__,3));
define('TENANT_LANE_AUTOLOAD', getenv('TENANT_LANE_AUTOLOAD'));
require TENANT_LANE_AUTOLOAD;
$loader=new Composer\Autoload\ClassLoader();
if (getenv('TENANT_USE_SOURCE') === '1') {
    $loader->addPsr4('Tenant\\Laravel\\',TENANT_SOURCE_ROOT.'/packages/laravel/src');
    $loader->addPsr4('Tenant\\',TENANT_SOURCE_ROOT.'/packages/core/src');
}
$loader->addPsr4('TenantLab\\',__DIR__);
$loader->register(true);
