<?php
require __DIR__.'/lifecycle-support.php';
use Tenant\Postgres\OperationKey;
$scenario=$argv[1]??throw new RuntimeException('Scenario required');
$r=makeResource($scenario);$export=null;
if (str_starts_with($scenario,'drop')) $export=$journal->request(exportIntent($r),new OperationKey('lab',$scenario.'-export'),$allow);
file_put_contents($m['root'].'/'.$scenario.'.json',json_encode(['resource'=>get_object_vars($r),'export'=>$export],JSON_THROW_ON_ERROR));
echo json_encode(['status'=>'passed','resource'=>$r->resource_ref,'database'=>$r->database_name]),"\n";
