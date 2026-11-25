<?php
require __DIR__.'/Harness.php';

$app=flexApp();set_exception_handler(function(Throwable $e):void{fwrite(STDERR,(string)$e);exit(1);});
$app['events']->listen(Illuminate\Queue\Events\JobAttempted::class,fn()=>print('WORKER attempted state='.$app->make(Tenant\Contracts\TenantRuntime::class)->current()->kind.PHP_EOL));
$app['events']->listen(Illuminate\Queue\Events\JobExceptionOccurred::class,function($event):void{fwrite(STDERR,(string)$event->exception);});
echo 'WORKER pid='.getmypid().PHP_EOL;
$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);
$exit=$kernel->call('queue:work',['connection'=>'database','--queue'=>'default','--stop-when-empty'=>true,'--tries'=>1,'--sleep'=>0,'--timeout'=>30,'--max-jobs'=>20]);
echo $kernel->output();if($exit!==0)exit($exit);
verify($app->make(Tenant\Contracts\TenantRuntime::class)->current()->kind==='central','FLEX-COHORT-01 worker cleanup central');
