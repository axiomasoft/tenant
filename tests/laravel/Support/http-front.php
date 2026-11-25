<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/CorpusRunner.php';
require_once __DIR__.'/HttpScenarios.php';
$manifest=json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')),true,flags:JSON_THROW_ON_ERROR);
$h=new TenantLab\LabHost($manifest,getenv('TENANT_SESSION_DRIVER'),decorate:getenv('TENANT_DECORATED')==='1'?fn($r)=>new TenantLab\RuntimeDecorator($r):null);
$kernel=TenantLab\HttpScenarios::kernel($h);
TenantLab\HttpScenarios::boundary($h)->handle(Illuminate\Http\Request::capture(),$kernel)->send();
