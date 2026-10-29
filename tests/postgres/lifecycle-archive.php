<?php
require __DIR__.'/lifecycle-support.php';
use Tenant\Postgres\{Admission,DeleteIntent,ExportIntent,OperationKey,ExportRef,ArchiveManifest};
use Tenant\Postgres\Internal\LifecycleJournal;
$r=makeResource('archive');
$missing=new LifecycleJournal($c,$verify,null,$anchor,$policy);
refuse(fn()=>$missing->request(exportIntent($r),new OperationKey('lab','missing'),$allow),'archive_unverified');
$noPolicy=new LifecycleJournal($c,$verify,$archives,$anchor);
refuse(fn()=>$noPolicy->request(exportIntent($r),new OperationKey('lab','policy'),$allow),'archive_unverified');
refuse(fn()=>$journal->request(exportIntent($r),new OperationKey('lab','deny'),new Admission(static fn()=>false)),'denied');
check($c->table('tenant_pg_lifecycle_operations')->count()===0,'Preflight refusals created operations');
$export=$journal->request(exportIntent($r),new OperationKey('lab','original-export'),$allow);check($export->status==='succeeded','Export did not complete');
check($journal->request(exportIntent($r),new OperationKey('lab','original-export'),$allow)==$export,'Lost response replay changed export');
$receipt=$journal->queryExport($export->exportRef,$allow);check($receipt->state==='ready' && $receipt->manifest!==null,'Original archive unavailable');
refuse(fn()=>$journal->request(new ExportIntent($r->resource_ref,$r->incarnation,'archive:other','policy:lab'),new OperationKey('lab','original-export'),$allow),'idempotency_conflict');
refuse(fn()=>$journal->request(deleteIntent($r,$export),new OperationKey('lab','original-export'),$allow),'idempotency_conflict');
$other=makeResource('other');
refuse(fn()=>new DeleteIntent($other->resource_ref,$other->incarnation,$export->exportRef,'policy:lab'),'Foreign');
// Independent valid checksum but forged resource ownership is never accepted.
$mfest=$receipt->manifest;$path=$archiveDir.'/'.$mfest->exportId.'.dump';$manifestPath=$path.'.manifest';$bytes=file_get_contents($path);$manifestBytes=file_get_contents($manifestPath);
$foreign=get_object_vars($mfest);$foreign['resourceRef']=$other->resource_ref;file_put_contents($manifestPath,json_encode($foreign,JSON_THROW_ON_ERROR));
refuse(fn()=>$journal->queryExport($export->exportRef,$allow),'archive_unverified');file_put_contents($manifestPath,$manifestBytes);
foreach (['hash','size','missing','unreadable'] as $fault) {
    match($fault) {'hash'=>file_put_contents($path,str_repeat('x',strlen($bytes))),'size'=>file_put_contents($path,'short'),'missing'=>rename($path,$path.'.saved'),'unreadable'=>chmod($path,0000)};
    refuse(fn()=>$journal->request(deleteIntent($r,$export),new OperationKey('lab','bad-'.$fault),$allow),'archive_unverified');
    if ($fault==='missing') rename($path.'.saved',$path);
    chmod($path,0600);file_put_contents($path,$bytes);check($c->selectOne('SELECT oid FROM pg_database WHERE datname=?',[$r->database_name])!==null,'Bad archive caused DROP');
}
$deleted=$journal->request(deleteIntent($r,$export),new OperationKey('lab','original-delete'),$allow);check($deleted->status==='succeeded' && $deleted->observedEffect==='dropped','Delete not observed');
check($c->selectOne('SELECT oid FROM pg_database WHERE datname=?',[$r->database_name])===null,'Target still exists');
check($journal->request(deleteIntent($r,$export),new OperationKey('lab','original-delete'),$allow)==$deleted,'Tombstone receipt lost');
check($c->table('tenant_pg_lifecycle_outbox')->where('operation_id',$deleted->operationId)->count()===1,'Duplicate durable result');
check($c->selectOne('SELECT oid FROM pg_database WHERE datname=?',[$other->database_name])!==null,'Foreign target dropped');
refuse(fn()=>$journal->request(new DeleteIntent($r->resource_ref,$r->incarnation,$export->exportRef,'policy:changed'),new OperationKey('lab','original-delete'),$allow),'idempotency_conflict');
file_put_contents($m['root'].'/archive-result.json',json_encode(['export'=>get_object_vars($export),'delete'=>get_object_vars($deleted),'resource'=>get_object_vars($r)],JSON_THROW_ON_ERROR));
echo json_encode(['status'=>'passed','scope'=>'real pg_dump, original export/delete receipts, ownership and missing/hash/size/unreadable refusal, exactly once tombstone/outbox','exportId'=>$export->exportRef->exportId,'deleteId'=>$deleted->operationId]),"\n";
