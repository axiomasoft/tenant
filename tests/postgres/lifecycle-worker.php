<?php
require __DIR__.'/lifecycle-support.php';
use Tenant\Postgres\{OperationKey,DeleteIntent,ExportRef,OperationRef};
use Tenant\Postgres\Internal\LifecycleJournal;
$scenario=$argv[1];$mode=$argv[2];$task=json_decode(file_get_contents($m['root'].'/'.$scenario.'.json'),true,flags:JSON_THROW_ON_ERROR);$r=(object)$task['resource'];
$checkpoint=function(string $point,object $row)use($mode,$c,$scenario):void {
    $selected=match($mode){'after-export'=>'after-export','after-drop'=>'after-drop',default=>'before-effect'};
    if ($point!==$selected) return;
    echo json_encode(['hook'=>$point,'operationId'=>$row->id,'pid'=>$c->selectOne('SELECT pg_backend_pid() AS pid')->pid]),"\n";flush();
    if ($mode==='lost-lock') $c->select('SELECT pg_advisory_unlock_all()');
    if ($mode==='exit-export' || $mode==='exit-drop') exit(73);
    if ($mode!=='normal') $c->select('SELECT pg_advisory_lock(hashtextextended(?,0))',['lab:barrier:'.$scenario]);
};
$journal=new LifecycleJournal($c,$verify,$archives,$anchor,$policy,$checkpoint);
$intent=exportIntent($r);
if ($task['export']!==null) { $e=$task['export']['exportRef'];$intent=new DeleteIntent($r->resource_ref,$r->incarnation,new ExportRef($e['exportId'],new OperationRef(...$e['operation'])),'policy:lab'); }
try { $result=$journal->request($intent,new OperationKey('lab',$scenario),$allow);echo json_encode(['result'=>get_object_vars($result)]),"\n"; }
catch (Throwable $e) { echo json_encode(['error'=>get_class($e),'message'=>$e->getMessage()]),"\n";exit(74); }
