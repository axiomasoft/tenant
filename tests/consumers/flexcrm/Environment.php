<?php

declare(strict_types=1);

$path = getenv('TENANT_LAB_MANIFEST');
if (!is_string($path) || !is_file($path)) { throw new RuntimeException('An explicit isolated lab manifest is required.'); }
$m = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
if (!preg_match('/^[a-f0-9]{12}$/D', $m['runId']) || $m['project'] !== 'tenant_lab_'.$m['runId'] || $m['root'] !== '/tmp/'.$m['project']) { throw new RuntimeException('Not an owned disposable lab.'); }
foreach ($m['databases'] as $key=>$db) {
    if ($db !== 'tenant_'.$m['runId'].'_'.$key.'_test') { throw new RuntimeException('DB allowlist mismatch.'); }
}
$credentials = json_decode(file_get_contents($m['credentials']), true, flags: JSON_THROW_ON_ERROR);
$sessionDriver=getenv('FLEXCRM_SESSION_DRIVER') ?: 'file';
if (!in_array($sessionDriver,['file','redis'],true)) throw new RuntimeException('Unsupported isolated session driver');
$pairs = ['APP_ENV'=>'testing','APP_KEY'=>'base64:'.base64_encode(str_repeat('q', 32)), 'DB_CONNECTION'=>'central', 'DB_HOST'=>'127.0.0.1', 'DB_PORT'=>(string) $m['ports']['pg'], 'DB_DATABASE'=>$m['databases']['central'], 'DB_USERNAME'=>$m['runtime'], 'DB_PASSWORD'=>$credentials['runtimePassword'], 'DB_URL'=>'', 'REDIS_HOST'=>'127.0.0.1', 'REDIS_PORT'=>(string) $m['ports']['redis'], 'CACHE_STORE'=>'tenant', 'SESSION_DRIVER'=>$sessionDriver, 'SESSION_STORE'=>'session', 'SESSION_CONNECTION'=>'cache', 'SESSION_LIFETIME'=>'120', 'QUEUE_CONNECTION'=>'database', 'MAIL_MAILER'=>'array', 'BROADCAST_CONNECTION'=>'log', 'APP_DEBUG'=>'false', 'APP_URL'=>'http://tenant-a.test', 'MEDIA_DISK'=>'media-test'];
foreach ($pairs as $k=>$v) { $_SERVER[$k]=$_ENV[$k]=$v; putenv($k.'='.$v); }
unset($credentials,$pairs);
