<?php
require __DIR__.'/Harness.php';
$app=flexApp();set_exception_handler(function(Throwable $e):void{fwrite(STDERR,(string)$e);exit(1);});flexReset($app);$s=flexSeed($app);extract($s);
$manager=$app->make(CoreX\Context\Contracts\TenancyManager::class);$user=App\Models\User::query()->findOrFail($actor);
$role=AzGuard\Models\Role::query()->create(['name'=>'member','level'=>10]);$role->class_name=App\Guards\App\Roles\MemberRole::class;$role->save();$user->roles()->attach($role->getKey());
Illuminate\Support\Facades\Auth::guard('web')->setUser($user);
$ref=flexRef($w);$other=flexRef($x);
$manager->runFor($accounts['a'],$ref,function()use($app,$actor,$w,$x,$manager,$other,$accounts):void{
    $draft=new CoreX\Tasks\Task\Data\TaskDraft($w,'Actual Tasks owner command',new CoreX\Tasks\Task\Data\ActorData(CoreX\Audit\Enums\ActorType::User,$actor),authorId:$actor,assigneeId:$actor);
    $task=$app->make(CoreX\Tasks\Task\Contracts\CreateTaskCommand::class)->handle($draft);
    $record=$app->make(FlexCRM\Tasks\Repositories\TaskRepository::class)->paginate([])->items();
    verify(count($record)===1 && $record[0]->getKey()===$task->id && $record[0]->workspace_id===$w,'REL-01 actual Tasks command via public port and actual AzGuard permission');
    $app->make(CoreX\Tasks\Task\Contracts\UpdateTaskCommand::class)->handle(new CoreX\Tasks\Task\Data\UpdateTaskData($task->id,$draft->actor,['title'],title:'Updated task'));
    $app->make(CoreX\Tasks\Task\Contracts\CompleteTaskCommand::class)->handle(new CoreX\Tasks\Task\Data\CompleteTaskData($task->id,$draft->actor));
    verify($app->make(FlexCRM\Tasks\Repositories\TaskRepository::class)->fresh($record[0])->status===CoreX\Tasks\Task\Enums\TaskStatus::Done,'REL-01 current immutable TaskView mutation access contract');
    $manager->runFor($accounts['a'],$other,fn()=>verify($app->make(FlexCRM\Tasks\Repositories\TaskRepository::class)->paginate([])->total()===0,'REL-01 Tasks repository exact foreign W visibility'));
    Illuminate\Support\Facades\DB::table('users')->where('id',$actor)->update(['is_active'=>false]);
    denies(fn()=>$app->make(CoreX\Tasks\Task\Contracts\CreateTaskCommand::class)->handle($draft),'AUTH-01 Tasks freshly disabled actor refused');
});
$manager->end();echo "COMPLETE FlexCRM Tasks public cohort\n";
