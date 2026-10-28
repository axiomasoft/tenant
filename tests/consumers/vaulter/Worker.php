<?php

declare(strict_types=1);
require __DIR__.'/Host.php';
$m=json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')),true,flags:JSON_THROW_ON_ERROR);
$h=new TenantVaulter\Host($m);
$h->worker();
$kind=$h->runtime->current()->kind;
if(!in_array($kind,['missing','central'],true))throw new RuntimeException('Worker baseline was not restored');
echo 'PASS worker restored explicit '.$kind.' baseline after actual jobs'.PHP_EOL;
