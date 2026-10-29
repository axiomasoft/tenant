<?php
require __DIR__.'/review-regression-support.php';
use Tenant\Postgres\{Admission,OperationKey,OperationRef};
$ref=new OperationRef('lab','00000000-0000-4000-8000-000000000099','lab:not-admitted','inc:missing');$rows=[];
foreach(['queryOperation'=>'query','reconcile'=>'reconcile','cancelIntent'=>'cancel'] as $method=>$purpose){
 $calls=[];$queries=0;$c->beforeExecuting(static function()use(&$queries):void{++$queries;});
 $deny=new Admission(static function(string $p,string $target)use(&$calls):bool{$calls[]=[$p,$target];return false;});
 try{$owner->$method($ref,$deny);throw new LogicException('Denied call returned');}
 catch(Throwable $e){$ok=get_class($e)==='Tenant\\Exceptions\\ContextDenied'&&$e->reason()==='denied'&&$e->getMessage()==='denied'&&$calls===[[$purpose,$ref->resourceRef]]&&$queries===0;$rows[]=['method'=>$method,'status'=>$ok?'passed':'failed','actualType'=>get_class($e),'reason'=>method_exists($e,'reason')?$e->reason():null,'admissionCalls'=>$calls,'sqlBeforeDenial'=>$queries];}
}
foreach($rows as $row)echo json_encode($row),"\n";
check(count(array_filter($rows,static fn(array $r):bool=>$r['status']!=='passed'))===0,'Admission must precede public operation visibility');
if(in_array('--happy',$argv,true)){
 $receipt=$owner->claim(reviewIntent('f3-original'),new OperationKey('lab','f3-original'),$permit);$original=operationRef($receipt);
 check($owner->queryOperation($original,$permit)==$receipt,'Authorized original query changed');
 check($owner->reconcile($original,$permit)==$receipt,'Authorized original reconcile changed');
 try{$owner->cancelIntent($original,$permit);throw new LogicException('Completed operation cancelled');}
 catch(Tenant\Exceptions\ContextUnavailable $failure){check($failure->reason()==='unavailable','Completed operation error changed');}
}
echo json_encode(['status'=>'passed','scope'=>'fresh denial before any metadata SQL for all3 operation ports; authorized original query/reconcile unchanged','cases'=>3]),"\n";
