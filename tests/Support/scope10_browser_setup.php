<?php
declare(strict_types=1);
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProjectExecution\ProjectExecutionWriter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
require dirname(__DIR__,2).'/vendor/autoload.php';
$path=$argv[1]??'';$temp=realpath(sys_get_temp_dir());$dir=$path===''?false:realpath(dirname($path));$name=basename($path);
if($temp===false||$dir!==$temp||!str_starts_with($name,'company-os-scope10-browser-')||!str_ends_with($name,'.sqlite')){fwrite(STDERR,"Refusing non-Scope 10 temp database.\n");exit(64);}
if(is_file($path))unlink($path);touch($path);
$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Kernel::class)->bootstrap();
config(['app.env'=>'testing','app.url'=>env('APP_URL','http://127.0.0.1:8770'),'database.default'=>'sqlite','database.connections.sqlite.database'=>$path,'product_ux.organization_admission_enabled'=>false]);DB::purge('sqlite');
Artisan::call('migrate:fresh',['--database'=>'sqlite','--force'=>true]);
$org=Organization::create(['name'=>'Scope 10 Browser Company','slug'=>'scope10-browser-'.strtolower((string)Str::ulid())]);
$workspace=null;$users=[];
foreach([['Owner','scope10-owner@example.test','owner'],['Assignee','scope10-assignee@example.test','member'],['Reviewer','scope10-reviewer@example.test','member'],['Reader','scope10-reader@example.test','member']] as [$name,$email,$role]){
 $user=User::create(['name'=>$name,'email'=>$email,'email_verified_at'=>now(),'password'=>Hash::make('not-used'),'is_active'=>true]);
 OrganizationUser::create(['organization_id'=>$org->id,'user_id'=>$user->id,'role'=>$role,'organization_role'=>$role,'membership_status'=>'active','access_epoch'=>1,'lifecycle_version'=>1,'permissions'=>[],'joined_at'=>now()]);
 ProductAccountEligibility::create(['user_id'=>$user->id,'mode'=>'single','product_organization_id'=>$org->id,'classification_version'=>'scope10-browser','classified_at'=>now(),'evidence_ref'=>'scope10-browser']);$users[]=$user;
}
$workspace=Workspace::create(['organization_id'=>$org->id,'owner_user_id'=>$users[0]->id,'name'=>'Notification Workspace','slug'=>'scope10-notification','type'=>Workspace::TYPE_SHARED,'status'=>Workspace::STATUS_ACTIVE]);
foreach($users as $index=>$user)$workspace->users()->attach($user->id,['role'=>$index===0?'owner':'member','joined_at'=>now()]);
$writer=app(ProjectExecutionWriter::class);$project=$writer->createProject($users[0],$workspace,['name'=>'Notification Journey','purpose'=>'Notify only accountable people','expected_outcome'=>'Human returns safely to work']);
$writer->addMember($users[0],$project->fresh(),$users[1],$workspace,['member'],$project->fresh()->plan_version);
$writer->addMember($users[0],$project->fresh(),$users[2],$workspace,['member'],$project->fresh()->plan_version);
echo json_encode(['database'=>$path,'organization_id'=>$org->id,'project_id'=>$project->id,'owner_id'=>$users[0]->id,'assignee_id'=>$users[1]->id,'reviewer_id'=>$users[2]->id,'reader_id'=>$users[3]->id],JSON_UNESCAPED_SLASHES).PHP_EOL;
