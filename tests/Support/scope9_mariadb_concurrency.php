<?php

declare(strict_types=1);

use App\Models\ActionExecution;
use App\Models\ActionScheduleRevision;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActionExecution\ActionExecutionWriter;
use App\Services\ProjectExecution\ProjectExecutionWriter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('SCOPE9_MARIADB_APPROVED') !== '1' || config('database.default') !== 'mariadb'
    || config('database.connections.mariadb.host') !== '127.0.0.1'
    || (int) config('database.connections.mariadb.port') !== 13329
    || config('database.connections.mariadb.database') !== 'co_s9_evidence') {
    fwrite(STDERR, "Scope 9 MariaDB guard rejected the environment.\n"); exit(64);
}
$mode = $argv[1] ?? '';
if ($mode === 'setup') {
    Artisan::call('migrate:fresh', ['--database' => 'mariadb', '--force' => true]);
    $org=Organization::create(['name'=>'S9 MariaDB','slug'=>'s9-mariadb']);
    $user=User::create(['name'=>'S9 Worker','email'=>'s9-worker@example.test','password'=>Hash::make('unused'),'email_verified_at'=>now(),'is_active'=>true]);
    OrganizationUser::create(['organization_id'=>$org->id,'user_id'=>$user->id,'role'=>'owner','organization_role'=>'owner','membership_status'=>'active','joined_at'=>now()]);
    $ws=Workspace::create(['organization_id'=>$org->id,'owner_user_id'=>$user->id,'name'=>'S9','slug'=>'s9','type'=>Workspace::TYPE_SHARED,'status'=>Workspace::STATUS_ACTIVE]); $ws->users()->attach($user->id,['role'=>'owner','joined_at'=>now()]);
    $s8=app(ProjectExecutionWriter::class); $project=$s8->createProject($user,$ws,['name'=>'Concurrent','purpose'=>'Verify','expected_outcome'=>'Converge']);
    $task=$s8->createAction($user,$project->fresh(),['title'=>'Concurrent action','done_condition'=>'One result','assigned_to'=>$user->id],$project->fresh()->plan_version);
    $setting=app(ActionExecutionWriter::class)->configure($user,$task,['run_type'=>'continuous','category'=>'task','frequency'=>'daily','interval'=>1,'execution_rule'=>'on_date','window_days_before'=>0,'starts_on'=>now()->toDateString(),'count_limit'=>null],$project->fresh()->plan_version);
    $executions=ActionExecution::where('task_id',$task->id)->orderBy('id')->get(); $retrySource=$executions[1]; $retrySource->update(['status'=>'missed','resolved_at_utc'=>now('UTC')]);
    echo json_encode(['user'=>$user->id,'revision'=>$setting->current_revision_id,'complete'=>$executions[0]->id,'retry'=>$retrySource->id]).PHP_EOL; exit;
}
$payload=json_decode(base64_decode($argv[2]??''),true,512,JSON_THROW_ON_ERROR); $tmp=str_replace('\\','/',sys_get_temp_dir()).'/company-os-scope9-';
foreach(['ready','go'] as $key){if(!str_starts_with(str_replace('\\','/',$payload[$key]??''),$tmp)){fwrite(STDERR,"Unsafe barrier.\n");exit(65);}}
touch($payload['ready']); $deadline=microtime(true)+20; while(!is_file($payload['go'])&&microtime(true)<$deadline){usleep(20000);} if(!is_file($payload['go'])){exit(66);}
try {$writer=app(ActionExecutionWriter::class); $user=User::findOrFail($payload['user']); $value=match($mode){
    'generate'=>$writer->generateRevision(ActionScheduleRevision::findOrFail($payload['revision']),CarbonImmutable::now()->addDays(70)),
    'retry'=>$writer->retry($user,ActionExecution::findOrFail($payload['execution']),(string)Str::uuid())->id,
    'complete'=>$writer->complete($user,ActionExecution::findOrFail($payload['execution']),['operation_id'=>(string)Str::uuid()])->status,
    'skip'=>$writer->skip($user,ActionExecution::findOrFail($payload['execution']),'concurrent skip',(string)Str::uuid())->status,
}; echo json_encode(['status'=>'ok','value'=>$value]).PHP_EOL;
} catch (Throwable $e) {echo json_encode(['status'=>'rejected','class'=>$e::class]).PHP_EOL;}
