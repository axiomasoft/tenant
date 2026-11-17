<?php
require __DIR__.'/review-regression-support.php';
use Tenant\Postgres\{OperationKey,OperationRef,ProvisionIntent};
use Tenant\Postgres\Data\ClaimRequest;
$mode=$argv[1];$scenario=$argv[2]??'review';$key=new OperationKey('lab',$scenario);$intent=reviewIntent($scenario);
if ($mode==='f1-a') {
 $armed=false;$paused=false;$c->beforeExecuting(function(string $query,array $bindings)use(&$armed,&$paused,$c):void{
  if(str_contains($query,'SELECT pg_advisory_lock(')&&str_starts_with($bindings[0]??'','tenant-template-integrity:'))$armed=true;
  if($armed&&!$paused&&str_contains($query,'tenant_pg_templates')){$paused=true;barrier('session-template-held');}
 });
} elseif ($mode==='f2-a') {
 $locks=0;$c->beforeExecuting(function(string $query,array $bindings)use(&$locks,$key):void{if(str_contains($query,'pg_advisory_xact_lock')&&($bindings[0]??null)==='tenant:operation:'.$key->storageKey()&&++$locks===4)barrier('physical-complete-before-final-claim');});
} elseif ($mode==='f3-marker' || $mode==='f3-before-create' || $mode==='f3-accepted') {
 $paused=false;$c->beforeExecuting(function(string $query,array $bindings)use(&$paused,$mode):void {
  $match=match($mode){'f3-marker'=>str_contains($query,'update "tenant_pg_provision_attempts"')&&in_array('succeeded',$bindings,true),'f3-accepted'=>str_contains($query,'update "tenant_pg_provision_attempts"')&&in_array('running',$bindings,true),default=>str_contains($query,'SELECT pg_advisory_lock(')&&str_starts_with($bindings[0]??'','tenant-template-integrity:')};
  if(!$paused&&$match){$paused=true;barrier($mode);}
 });
}
try {
 if($mode==='seed-spare') { $receipt=$pool->fill($intent,$key,$permit);echo json_encode(['receipt'=>$receipt]),"\n"; }
 elseif($mode==='f1-b') {$q=$integrity->qualify(new ClaimRequest('f1-b',$cluster,$template,$intent->digest()));echo json_encode(['result'=>'qualified','template'=>$q->templateId]),"\n";}
 elseif($mode==='retry') { $receipt=$owner->claim($intent,$key,$permit);echo json_encode(['receipt'=>$receipt]),"\n"; }
 elseif($mode==='cancel-original') { $receipt=$owner->claim($intent,$key,$permit);$ref=new OperationRef($receipt->key->namespace,$receipt->operationId,$receipt->resourceRef,$receipt->incarnation);$cancel=$owner->cancelIntent($ref,$permit);$again=$owner->claim($intent,$key,$permit);echo json_encode(['retryReceipt'=>$receipt,'cancelled'=>$cancel,'after'=>$again]),"\n"; }
 elseif($mode==='recover') { $receipt=$owner->claim($intent,$key,$permit);$ref=new OperationRef($receipt->key->namespace,$receipt->operationId,$receipt->resourceRef,$receipt->incarnation);$reconciled=$owner->reconcile($ref,$permit);$again=$owner->claim($intent,$key,$permit);echo json_encode(['retryReceipt'=>$receipt,'reconciled'=>$reconciled,'after'=>$again]),"\n"; }
 else {$receipt=$owner->claim($intent,$key,$permit);echo json_encode(['receipt'=>$receipt]),"\n";}
} catch(Throwable $e) {echo json_encode(['error'=>get_class($e),'reason'=>method_exists($e,'reason')?$e->reason():null,'message'=>$e->getMessage()]),"\n";exit(74);}
