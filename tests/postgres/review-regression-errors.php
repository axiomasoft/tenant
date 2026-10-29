<?php
require __DIR__.'/review-regression-support.php';
use Tenant\Exceptions\TenantFailure;
use Tenant\Postgres\{Admission,OperationKey,OperationRef,ProvisionIntent,PostgresFactory,RecoveryIntent,ExportRef,DeleteIntent};
$rows=[];
function contractError(string $case,string $type,string $reason,Closure $work):void
{
 global $rows,$lastFailure;
 try{$work();$rows[]=['case'=>$case,'status'=>'failed','error'=>'No refusal'];}
 catch(Throwable $e){$lastFailure=$e;$actual=method_exists($e,'reason')?$e->reason():null;$safe=$e->getMessage()===$reason;$rows[]=['case'=>$case,'status'=>$e instanceof TenantFailure&&get_class($e)===$type&&$actual===$reason&&$safe?'passed':'failed','actualType'=>get_class($e),'expectedType'=>$type,'reason'=>$actual,'expectedReason'=>$reason,'safeMessage'=>$safe,'causeCount'=>method_exists($e,'causes')?count($e->causes()):null];}
}
$intent=reviewIntent('f1-original');
contractError('conflict','Tenant\\Exceptions\\IdempotencyConflict','idempotency_conflict',fn()=>$owner->claim(new ProvisionIntent('lab:changed',$cluster,$template,7,$proof['digest'],[]),new OperationKey('lab','f1-original'),$permit));
contractError('denied','Tenant\\Exceptions\\ContextDenied','denied',fn()=>$owner->claim(reviewIntent('error-denied'),new OperationKey('lab','error-denied'),new Admission(static fn()=>false)));
$noAnchor=PostgresFactory::owner($c,$db->getDatabaseManager(),$trust,$runtimeRoute,$name,$m['runtime'],$templateMarker,$archives,null,$policy);
$fake=new ExportRef('00000000-0000-4000-8000-000000000000',new OperationRef('lab','00000000-0000-4000-8000-000000000001','lab:none','inc:none'));
$recovery=new RecoveryIntent('restore',$fake,str_repeat('a',64),'lab:none','inc:none',$cluster,$base.'_none_test','recovery:restore');
contractError('no-anchor','Tenant\\Exceptions\\RecoveryUnsupported','recovery_unsupported',fn()=>$noAnchor->admitRecovery($recovery,$permit));
$r=makeResource('error_contract');
contractError('archive','Tenant\\Exceptions\\ArchiveUnverified','archive_unverified',fn()=>$owner->requestDelete(new DeleteIntent($r->resource_ref,$r->incarnation,null,'policy:lab'),new OperationKey('lab','error-archive'),$permit));
contractError('original-missing','Tenant\\Exceptions\\OriginalOperationMissing','original_operation_missing',fn()=>$owner->queryOperation($fake->operation,$permit));
$original=$c->table('tenant_pg_provision_attempts')->where('key_hash',(new OperationKey('lab','f1-original'))->storageKey())->first();check($original!==null,'Original review state missing');
$c->table('tenant_pg_provision_attempts')->where('id',$original->id)->delete();
try{contractError('history','Tenant\\Exceptions\\RetentionHistoryMissing','retention_history_missing',fn()=>$owner->requestExport(exportIntent($r),new OperationKey('lab','error-history'),$permit));}
finally{$c->table('tenant_pg_provision_attempts')->insert((array)$original);}
// A real competing physical backend fences this exact original export target.
$fenced=$factory->make($cfg,'public-error-fence');$fenced->select('SELECT pg_advisory_lock(hashtextextended(?,0))',['tenant:physical:'.$r->database_name]);
try{contractError('quarantined','Tenant\\Exceptions\\ResourceQuarantined','resource_quarantined',fn()=>$owner->requestExport(exportIntent($r),new OperationKey('lab','error-fenced'),$permit));}
finally{$fenced->select('SELECT pg_advisory_unlock(hashtextextended(?,0))',['tenant:physical:'.$r->database_name]);$fenced->disconnect();}
// Accepted did not submit IO: completing its original key is allowed after the old fence exits.
$export=$owner->requestExport(exportIntent($r),new OperationKey('lab','error-fenced'),$permit);
$owner->requestDelete(deleteIntent($r,$export),new OperationKey('lab','error-fenced-delete'),$permit);
contractError('tombstone-conflict','Tenant\\Exceptions\\IdempotencyConflict','idempotency_conflict',fn()=>$owner->requestExport(new Tenant\Postgres\ExportIntent($r->resource_ref,$r->incarnation,'archive:changed','policy:lab'),new OperationKey('lab','error-fenced'),$permit));
$unknown=makeResource('error_unknown');$sentinel=new RuntimeException('postgres://fake:private@server/secret SELECT secret');
$store=new class($archives,$sentinel) implements Tenant\Postgres\Contracts\ArchiveStore {
    public int $writes=0;
    public function __construct(private Tenant\Postgres\Contracts\ArchiveStore $inner,private Throwable $failure){}
    public function write(object $r,string $op,string $id,string $destination):Tenant\Postgres\ArchiveManifest{++$this->writes;$this->inner->write($r,$op,$id,$destination);throw $this->failure;}
    public function verify(Tenant\Postgres\ArchiveManifest $m):bool{return $this->inner->verify($m);}
    public function recover(string $id):?Tenant\Postgres\ArchiveManifest{return $this->inner->recover($id);}
};
$faultOwner=PostgresFactory::owner($c,$db->getDatabaseManager(),$trust,$runtimeRoute,$name,$m['runtime'],$templateMarker,$store,$anchor,$policy);
contractError('unknown','Tenant\\Exceptions\\OperationUnknown','operation_unknown',fn()=>$faultOwner->requestExport(exportIntent($unknown),new OperationKey('lab','error-unknown'),$permit));
check($lastFailure->causes()===[$sentinel],'Unknown operation lost original trusted cause');
$original=$faultOwner->requestExport(exportIntent($unknown),new OperationKey('lab','error-unknown'),$permit);
check($original->status==='unknown' && $store->writes===1,'Unknown public retry reexecuted native IO');
// Kernel denial retains the original trusted Throwable without exposing it as public text.
try{(new Admission(static function()use($sentinel):bool{throw $sentinel;}))->assertAllowed('export','lab:any');throw new LogicException('Missing denial');}
catch(Tenant\Exceptions\ContextDenied $failure){check($failure->causes()===[$sentinel] && $failure->getMessage()==='denied','Cause preservation or safe diagnostic failed');}
foreach($rows as $row)echo json_encode($row),"\n";
check(count(array_filter($rows,static fn(array $r):bool=>$r['status']!=='passed'))===0,'Frozen public error contract failed');
echo json_encode(['status'=>'passed','scope'=>'exact frozen public exception types, TenantFailure, stable reason and safe message; no parsing exception text','cases'=>count($rows)]),"\n";
