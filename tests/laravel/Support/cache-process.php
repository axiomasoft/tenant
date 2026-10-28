<?php
require __DIR__.'/bootstrap.php';
$manifest=json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')),true,flags:JSON_THROW_ON_ERROR);
$h=new TenantLab\LabHost($manifest);
$h->runtime->run($h->selection($argv[1]),$h->purpose(),function()use($h,$argv){
    $cache=$h->cache();$lock=$cache->lock($argv[2].':lock',15);$acquired=$lock->get();
    $result=['pid'=>getmypid(),'resource'=>$argv[1],'value'=>$cache->get($argv[2]),'lockAcquired'=>$acquired];
    if($acquired)$lock->release();
    echo json_encode($result,JSON_THROW_ON_ERROR);
});
