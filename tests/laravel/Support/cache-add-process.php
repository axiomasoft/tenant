<?php
require __DIR__.'/bootstrap.php';
$m=json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')),true,flags:JSON_THROW_ON_ERROR);
$h=new TenantLab\LabHost($m);
$h->runtime->run($h->selection(),$h->purpose(),function()use($h,$argv,$m){
    $cache=$h->cache();$barrier=$m['root'].'/'.$argv[1];
    file_put_contents($barrier.'.ready'.$argv[2],'ready');
    $deadline=microtime(true)+15;
    while(!file_exists($barrier.'.go')){if(microtime(true)>$deadline)throw new RuntimeException('claim barrier timeout');usleep(1000);}
    $claimed=$cache->add($argv[1],$argv[2],60);
    echo json_encode(['pid'=>getmypid(),'claimant'=>$argv[2],'claimed'=>$claimed],JSON_THROW_ON_ERROR);
});
