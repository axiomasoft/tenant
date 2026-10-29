<?php
require __DIR__.'/lifecycle-support.php';
use Tenant\Data\{BindingExpectation,BindingVerificationInput};
use Tenant\Postgres\{Admission,DeleteIntent,OperationKey,RecoveryIntent,RecoveryOperator};
use Tenant\Postgres\Internal\{AnchorHistory,LifecycleJournal,PostgresBindingVerifier};
use Tenant\Postgres\Data\PhysicalAccess;
function nativeTool(array $args,?string $output=null):void
{
    global $cfg;
    $errors=fopen('php://temp','w+');$desc=[0=>['file','/dev/null','r'],1=>$output===null?['file','/dev/null','w']:['file',$output,'x'],2=>$errors];
    $env=['PGHOST'=>$cfg['host'],'PGPORT'=>(string)$cfg['port'],'PGUSER'=>$cfg['username'],'PGPASSWORD'=>$cfg['password']];
    $p=proc_open($args,$desc,$pipes,env_vars:$env);$code=is_resource($p)?proc_close($p):-1;
    rewind($errors);$message=stream_get_contents($errors);fclose($errors);check($code===0,'Native PG tool failed: '.$message);
}
$catalogDump=$m['root'].'/old-catalog.dump';nativeTool(['/usr/lib/postgresql/16/bin/pg_dump','-Fc','--no-owner','--no-privileges','-d',$m['central']],$catalogDump);
$r=makeResource('restore');$export=$journal->request(exportIntent($r),new OperationKey('lab','restore-export'),$allow);$manifest=$journal->queryExport($export->exportRef,$allow)->manifest;
$delete=$journal->request(deleteIntent($r,$export),new OperationKey('lab','restore-delete'),$allow);
$operator=new RecoveryOperator($c,$journal,$route,$m['runtime'],$runtimeRoute);
$intent=new RecoveryIntent('restore',$export->exportRef,$manifest->sha256,$r->resource_ref,$r->incarnation,$r->cluster_id,$r->database_name,'recovery:restore');
$noAnchor=new LifecycleJournal($c,$verify,$archives,null,$policy);$unsupported=new RecoveryOperator($c,$noAnchor,$route,$m['runtime'],$runtimeRoute);
refuse(fn()=>$unsupported->admit($intent,$allow),'recovery_unsupported');
refuse(fn()=>$operator->admit($intent,new Admission(static fn()=>false)),'denied');
$plan=$operator->admit($intent,$allow);check($c->selectOne('SELECT oid FROM pg_database WHERE datname=?',[$r->database_name])===null,'admitRecovery performed IO');
// Clone has a distinct retained logical identity and frozen destination.
$cloneRef='lab:clone_recovery';$cloneDb=$base.'_clone_recovery_test';$cloneId=(string)Illuminate\Support\Str::uuid7();
$c->table('tenant_pg_resources')->insert(['id'=>$cloneId,'cluster_id'=>$r->cluster_id,'template_version'=>7,'status'=>'quarantined','resource_ref'=>$cloneRef,'incarnation'=>'inc:clone-original','database_name'=>$cloneDb,'route_revision'=>'route:1','recovery_generation'=>$anchor->generation()]);
$cloneIntent=new RecoveryIntent('clone',$export->exportRef,$manifest->sha256,$cloneRef,'inc:clone-original',$r->cluster_id,$cloneDb,'recovery:clone');$clonePlan=$operator->admit($cloneIntent,$allow);
$oldGeneration=$anchor->generation();$generation=$operator->quarantine($plan,$allow);check($generation!==$oldGeneration,'Recovery generation did not advance');
foreach ([$r->database_name,$cloneDb] as $target) {
    check(str_starts_with($target,$base.'_') && str_ends_with($target,'_test'),'Unsafe restore target');
    check($c->selectOne('SELECT oid FROM pg_database WHERE datname=?',[$target])===null,'Never overwrite an existing physical target');
    $c->getPdo()->exec('CREATE DATABASE "'.$target.'"');nativeTool(['/usr/lib/postgresql/16/bin/pg_restore','--exit-on-error','--no-owner','--no-privileges','-d',$target,$archiveDir.'/'.$manifest->exportId.'.dump']);
}
$restored=$operator->complete($plan,$generation,$allow);$clone=$operator->complete($clonePlan,$generation,$allow);
check($restored->resourceRef===$r->resource_ref && $restored->incarnation!==$r->incarnation,'Restore identity did not change');
check($clone->resourceRef!==$r->resource_ref && $clone->incarnation!==$restored->incarnation,'Clone identity reused');
refuse(fn()=>$journal->request(deleteIntent($r,$export),new OperationKey('lab','restore-delete'),$allow),'stale_incarnation');
refuse(fn()=>$journal->request(exportIntent($r),new OperationKey('lab','restore-export'),$allow),'stale_incarnation');
refuse(fn()=>$operator->complete($plan,$generation,$allow),'resource_quarantined');
$runtimeVerifier=new PostgresBindingVerifier($c,$runtimeRoute,$anchor);
$newRow=$c->table('tenant_pg_resources')->where('resource_ref',$restored->resourceRef)->first();
$input=new BindingVerificationInput(new BindingExpectation($restored->resourceRef,$restored->incarnation,$restored->routeRevision),new PhysicalAccess($runtimeRoute($newRow)));
refuse(fn()=>$runtimeVerifier->verify($input));$operator->reopen($restored,$allow);$operator->reopen($clone,$allow);$runtimeVerifier->verify($input);
refuse(fn()=>$runtimeVerifier->verify(new BindingVerificationInput(new BindingExpectation($r->resource_ref,$r->incarnation,$r->route_revision),new PhysicalAccess($runtimeRoute($r)))));
foreach ([$r->database_name,$cloneDb] as $target) {
    $probe=$factory->make([...$cfg,'database'=>$target],'restored-data');check($probe->table('records')->value('content')==='sentinel:restore','Real archive data not restored');$probe->disconnect();
}
// Restore an actually older control-plane backup into a separate disposable database.
$historyDb=$base.'_catalog_replay_test';$c->getPdo()->exec('CREATE DATABASE "'.$historyDb.'"');nativeTool(['/usr/lib/postgresql/16/bin/pg_restore','--exit-on-error','--no-owner','--no-privileges','-d',$historyDb,$catalogDump]);
$oldCatalog=$factory->make([...$cfg,'database'=>$historyDb],'catalog-replay');
refuse(fn()=>AnchorHistory::assertCurrent($oldCatalog,$anchor),'resource_quarantined');
// A restored DB-local epoch equal to the external value is still not proof of retained history.
$oldCatalog->table('tenant_pg_control')->where('id',1)->update(['recovery_generation'=>$generation]);
refuse(fn()=>AnchorHistory::assertCurrent($oldCatalog,$anchor),'retention_history_missing');
$oldJournal=new LifecycleJournal($oldCatalog,$verify,$archives,$anchor,$policy);
refuse(fn()=>$oldJournal->request(exportIntent($r),new OperationKey('lab','restore-export'),$allow),'retention_history_missing');
$oldCatalog->disconnect();
// Anchor loss denies already restored runtime admission, even with matching catalog+marker.
rename($anchorDir.'/anchor.json',$anchorDir.'/anchor.saved');refuse(fn()=>$runtimeVerifier->verify($input));rename($anchorDir.'/anchor.saved',$anchorDir.'/anchor.json');$runtimeVerifier->verify($input);
echo json_encode(['status'=>'passed','scope'=>'operator-only actual PG dump/restore and clone, external generation/new incarnation, stale receipts, no-anchor denial, real old control-plane restore missing history refused','oldGeneration'=>$oldGeneration,'generation'=>$generation,'oldIncarnation'=>$r->incarnation,'restoredIncarnation'=>$restored->incarnation,'cloneIncarnation'=>$clone->incarnation,'archiveHash'=>$manifest->sha256]),"\n";
