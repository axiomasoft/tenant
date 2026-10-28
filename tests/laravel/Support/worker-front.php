<?php
require __DIR__.'/bootstrap.php';
$manifest=json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')),true,flags:JSON_THROW_ON_ERROR);
$fixture=$argv[1];$decorated=($argv[2]??'0')==='1';
$p=new TenantLab\WorkerProbe($manifest,$decorated,$fixture);
if(str_starts_with($fixture,'__')) {
    if($fixture==='__resume') { $p->queue=$argv[3]; $p->worker(); $p->flush('replacement'); exit(0); }
    $op=substr($fixture,2);$p->dispatch($op,'a');$p->dispatch('sentinel','central');$p->flush('supervisor-start');
    $p->worker();$p->flush('unexpected-survivor');exit(99);
}
$result=$p->execute($fixture);
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
