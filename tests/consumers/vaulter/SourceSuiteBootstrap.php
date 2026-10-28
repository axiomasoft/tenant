<?php

declare(strict_types=1);

$manifest = json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')), true, flags: JSON_THROW_ON_ERROR);
$credentials = json_decode(file_get_contents($manifest['credentials']), true, flags: JSON_THROW_ON_ERROR);
$database = $manifest['databases']['template'];
if ($database !== 'tenant_'.$manifest['runId'].'_template_test') throw new RuntimeException('Source test DB guard failed');
$root = getenv('VAULTER_CONSUMER_CHECKOUT');
foreach (['APP_ENV'=>'testing','APP_KEY'=>'base64:'.base64_encode(str_repeat('t',32)), 'DB_CONNECTION'=>'pgsql','DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$manifest['ports']['pg'],
    'DB_DATABASE'=>$database,'DB_USERNAME'=>$manifest['admin'],'DB_PASSWORD'=>$credentials['adminPassword'],
    'TEST_TOKEN'=>'52','VAULTER_OPENAPI_VALIDATE'=>'0','CACHE_STORE'=>'array','QUEUE_CONNECTION'=>'sync'] as $key=>$value) {
    $_SERVER[$key]=$_ENV[$key]=$value;putenv($key.'='.$value);
}
require getenv('VAULTER_DEV_AUTOLOAD');
$loader = new Composer\Autoload\ClassLoader;
foreach (['Vaulter\\Drive\\Tests\\'=>'drive/tests','Vaulter\\Tenant\\'=>'tenant/src','Vaulter\\Drive\\'=>'drive/src','Vaulter\\Media\\'=>'media/src','Vaulter\\'=>'core/src'] as $prefix=>$path) {
    $loader->addPsr4($prefix,$root.'/packages/'.$path);
}
$loader->register(true);
