<?php
require __DIR__.'/Harness.php';
$app=flexApp();
config(['tenant.host_accounts'=>['tenant-a.test'=>'00000000-0000-4000-8000-000000000001','tenant-b.test'=>'00000000-0000-4000-8000-000000000002']]);
Illuminate\Support\Facades\Route::middleware(['web','auth',App\Http\Middleware\ResolveWorkspace::class])->get('/_tenant/selected/{workspace}',function(Illuminate\Http\Request $request){return response()->json(['actor'=>$request->user()->id,'workspace'=>app(App\Tenancy\WorkspaceContext::class)->currentKey(),'rows'=>Crm\Models\Project::query()->pluck('id')->all()]);});
Illuminate\Support\Facades\Route::middleware(['web','auth',App\Http\Middleware\ResolveWorkspace::class])->post('/_tenant/command/{workspace}',function(Illuminate\Http\Request $request){$id=$request->string('id')->toString();app(FlexCRM\Auth\Tenancy\WorkspaceCommand::class)->run($request->user()->id,app(App\Tenancy\WorkspaceContext::class)->currentKey(),fn()=>flexEffect($id,$request->user()->id));return response()->json(['created'=>$id]);});
Illuminate\Support\Facades\Route::middleware(['web','auth'])->post('/_tenant/revoke',function(Illuminate\Http\Request $request){Illuminate\Support\Facades\DB::table('wsp_workspace_members')->where('workspace_id','00000000-0000-4000-8000-000000000010')->where('user_id',$request->user()->id)->update(['status'=>'suspended']);return response()->json(['revoked'=>true]);});
Illuminate\Support\Facades\Route::middleware(['web','auth'])->get('/_tenant/termination',function()use($app){$app->terminating(function(){Illuminate\Support\Facades\DB::table('p53_effects')->updateOrInsert(['id'=>'http-terminate'],['event'=>'terminated','actor_id'=>null,'workspace_id'=>null]);});return response()->json(['accepted'=>true]);});
try{
    $request=Illuminate\Http\Request::capture();$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
    $response=$app->make(CoreX\Tenant\Http\CorexHttpBoundary::class)->handle($request,$kernel);
    if($app->make(Tenant\Contracts\TenantRuntime::class)->current()->kind!=='missing' || $app->make(App\Tenancy\WorkspaceContext::class)->current()!==null)throw new RuntimeException('HTTP runtime state leaked');
    error_log('CLEANUP missing workspace=null pid='.getmypid());$response->send();
}catch(Tenant\Exceptions\ContextDenied $failure){http_response_code(403);header('Content-Type: application/json');echo json_encode(['error'=>['code'=>'denied']]);}
