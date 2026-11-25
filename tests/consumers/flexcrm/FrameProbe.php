<?php
require __DIR__.'/Harness.php';
$state=(object)['value'=>'root','events'=>[],'partial'=>false,'fatal'=>false];
$compose=function($builder,$app)use($state):void{
    foreach(['h1','h2'] as $id){
        $factory=new class($state,$id) implements Tenant\Contracts\ParticipantFactory {
            public function __construct(private object $data,private string $id){}
            public function create():Tenant\Contracts\Facility{return new class($this->data,$this->id) implements Tenant\Contracts\Facility{
                public function __construct(private object $data,private string $id){}
                public function prepare(Tenant\Contracts\PhaseContext $ctx,Tenant\Contracts\FrameState $state):void{$state->put('old',$this->data->value);$state->put('captured',true);}
                public function activate(Tenant\Contracts\PhaseContext $ctx,Tenant\Contracts\FrameState $state):void{
                    $this->data->events[]=$this->id.'.activate';$this->data->value=$ctx->candidate()->context?->identity->id??'central';
                    if($this->id==='h2' && $this->data->partial)throw new RuntimeException('injected partial product activation');
                }
                public function restore(Tenant\Contracts\RestoreContext $ctx,Tenant\Contracts\FrameState $state):void{
                    $this->data->events[]=$this->id.'.restore';if($state->get('captured'))$this->data->value=$state->get('old');
                    if($this->id==='h2' && $this->data->fatal)throw new RuntimeException('injected product restore failure');
                }
                public function verify(Tenant\Contracts\RestoreContext $ctx,Tenant\Contracts\FrameState $state):void{}
            };}
        };
        $builder->register(new Tenant\Data\ParticipantDeclaration('flexcrm:test-'.$id,1,'resource',[],[],['flexcrm:test-'.$id.'/default'],[],[$id==='h1'?'flexcrm:workspace':'flexcrm:test-h1'],null,$factory));
    }
};
$app=flexApp($compose);set_exception_handler(function(Throwable $e):void{fwrite(STDERR,(string)$e);exit(1);});
if(getenv('FLEXCRM_POISON_WORKER')==='1') {
    $app['events']->listen(Illuminate\Queue\Events\JobProcessed::class,function()use($state):void{$state->fatal=true;});
    echo 'POISON WORKER pid='.getmypid().PHP_EOL;
    $app['queue.worker']->runNextJob('database','default',new Illuminate\Queue\WorkerOptions(sleep:0,maxTries:1,backoff:0));
    throw new RuntimeException('Worker continued past poisoned restore');
}
flexReset($app);$s=flexSeed($app);extract($s);
$manager=$app->make(CoreX\Context\Contracts\TenancyManager::class);$runtime=$app->make(Tenant\Contracts\TenantRuntime::class);$product=$app->make(App\Tenancy\WorkspaceContext::class);
$aw=flexRef($w);$ax=flexRef($x);$bw=$manager->runFor($accounts['b'],null,fn()=>flexRef($w));
$manager->runFor($accounts['a'],$aw,function()use($manager,$accounts,$aw,$ax,$bw,$w,$x,$product,$state):void{
    $original=$product->current();
    $manager->runFor($accounts['b'],$bw,function()use($manager,$accounts,$ax,$w,$x,$product):void{
        $original=$product->current();
        $manager->runFor($accounts['a'],$ax,fn()=>verify($product->currentKey()===$x,'EXT03-SNAPSHOTS actual A-B-A innermost X'));
        verify($product->current()===$original && $product->currentKey()===$w && $manager->context()->account->id===$accounts['b']->id,'EXT03-SNAPSHOTS B frame own exact snapshot restored');
    });
    verify($product->current()===$original && $manager->context()->account->id===$accounts['a']->id,'EXT03-SNAPSHOTS outer A model snapshot not overwritten');
    $state->partial=true;$state->events=[];
    denies(fn()=>$manager->runFor($accounts['b'],$bw,fn()=>throw new RuntimeException('not reached')),'EXT03-SNAPSHOTS partially activated h2 is journaled');
    $state->partial=false;
    verify(array_slice($state->events,-2)===['h2.restore','h1.restore'] && $product->current()===$original,'EXT03-SNAPSHOTS shared reverse journal h2-h1 and actual product restore');
});
$manager->end();verify($product->current()===null,'EXT03-SNAPSHOTS root product context clear');
// A separate actual worker attempt must stop when restore has poisoned its one runtime.
$manager->initialize($accounts['a']);
$manager->runFor($accounts['a'],$aw,fn()=>$app->make(CoreX\Context\Contracts\ContextJobPublisher::class)->publish('flexcrm:fixture','flexcrm:fixture','flexcrm:fixture',['actor'=>$actor,'workspace'=>$w,'id'=>'00000000-0000-4000-8000-000000000070']));
$manager->end();
$process=new Symfony\Component\Process\Process([PHP_BINARY,__FILE__],env:['TENANT_LAB_MANIFEST'=>getenv('TENANT_LAB_MANIFEST'),'FLEXCRM_CHECKOUT'=>getenv('FLEXCRM_CHECKOUT'),'FLEXCRM_POISON_WORKER'=>'1']);
$process->setTimeout(90);$exit=$process->run();echo $process->getOutput();echo $process->getErrorOutput();
verify($exit===70 && str_contains($process->getErrorOutput(),'tenant boundary stopped'),'EXT03-SNAPSHOTS failed restore poisons and stops real worker process');
$pending=$app['db']->connection('central')->table('jobs')->count();
verify($pending>=1,'EXT03-SNAPSHOTS poisoned attempt does not silently acknowledge queued follow-up');
echo "COMPLETE FlexCRM frame journal\n";
