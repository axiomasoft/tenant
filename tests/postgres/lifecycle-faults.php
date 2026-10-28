<?php
require __DIR__.'/lifecycle-support.php';
use Tenant\Postgres\{ArchiveManifest,OperationKey,OperationRef,ExportRef,DeleteIntent};
use Tenant\Postgres\Contracts\ArchiveStore;
use Tenant\Postgres\Internal\LifecycleJournal;
$r=makeResource('faults');$first=$journal->request(exportIntent($r),new OperationKey('lab','faults-first'),$allow);
$badStore=new class($archives) implements ArchiveStore {
    public function __construct(private ArchiveStore $inner) {}
    public function write(object $r,string $op,string $id,string $destination):ArchiveManifest { $m=$this->inner->write($r,$op,$id,$destination);return new ArchiveManifest($m->exportId,$m->operationId,'lab:foreign',$m->incarnation,$m->routeRevision,$m->recoveryGeneration,$m->destinationRef,$m->sha256,$m->sizeBytes); }
    public function verify(ArchiveManifest $m):bool { return true; } // A qualified content checksum cannot assert ownership.
    public function recover(string $id):?ArchiveManifest { return $this->inner->recover($id); }
};
$wrong=new LifecycleJournal($c,$verify,$badStore,$anchor,$policy);
refuse(fn()=>$wrong->request(exportIntent($r),new OperationKey('lab','faults-newer'),$allow),'archive_unverified');
$new=$c->table('tenant_pg_lifecycle_operations')->where('operation_key','faults-newer')->first();check($new->status==='unknown','Wrong ownership published readiness');
refuse(fn()=>$journal->request(deleteIntent($r,$first),new OperationKey('lab','faults-delete'),$allow));
// Exclude old backend, recover only the original durable export. No new writer execution.
$c->disconnect();gc_collect_cycles();$c->getPdo();
check($c->selectOne('SELECT pg_backend_pid() AS pid')->pid!==(int)$new->backend_pid,'Old backend was not excluded');
if($c->selectOne('SELECT pid FROM pg_stat_activity WHERE pid=?',[$new->backend_pid])!==null)$c->select('SELECT pg_terminate_backend(?)',[$new->backend_pid]);
for($attempt=0;$attempt<100 && $c->selectOne('SELECT pid FROM pg_stat_activity WHERE pid=?',[$new->backend_pid])!==null;$attempt++)usleep(10000);
$ref=new OperationRef($new->key_namespace,$new->id,$new->resource_ref,$new->incarnation);$done=$journal->reconcile($ref,$allow);check($done->status==='succeeded','Original durable export not reconciled');
refuse(fn()=>$journal->request(deleteIntent($r,$first),new OperationKey('lab','faults-delete'),$allow),'newer export');
$second=$journal->queryExport(new ExportRef($new->export_id,$ref),$allow);
check($second->manifest!==null,'New original manifest missing');
// Policy is checked again immediately before physical submission, after the registration.
$checks=0;$freshPolicy=static function(string $k,object $i,object $r)use(&$checks):bool { return ++$checks===1; };
$revoked=new LifecycleJournal($c,$verify,$archives,$anchor,$freshPolicy);
refuse(fn()=>$revoked->request(new DeleteIntent($r->resource_ref,$r->incarnation,new ExportRef($new->export_id,$ref),'policy:lab'),new OperationKey('lab','policy-revoked'),$allow),'fresh policy');
check($c->selectOne('SELECT oid FROM pg_database WHERE datname=?',[$r->database_name])!==null,'Revoked policy still dropped target');
// A catalog with forgotten original history refuses even with otherwise valid resource/marker.
$history=$c->table('tenant_pg_lifecycle_operations')->where('id',$first->operationId)->first();
$c->table('tenant_pg_lifecycle_operations')->where('id',$first->operationId)->delete();
refuse(fn()=>$journal->query($ref,$allow),'retention_history_missing');
$c->table('tenant_pg_lifecycle_operations')->insert((array)$history);
echo json_encode(['status'=>'passed','scope'=>'foreign ownership despite valid checksum, interrupted newer export invalidates older ready, original reconciliation, fresh policy revocation, retained history refusal']),"\n";
