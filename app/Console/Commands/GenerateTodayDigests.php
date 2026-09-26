<?php
namespace App\Console\Commands;
use App\Models\ActionExecution;
use App\Models\OrganizationUser;
use App\Models\Task;
use App\Models\User;
use App\Services\Notification\NotificationSourceWriter;
use App\Services\ProjectExecution\ProjectExecutionAccess;
use Illuminate\Console\Command;
class GenerateTodayDigests extends Command
{
    protected $signature='company-notifications:today-digests {--date=}';
    protected $description='Create at most one Today Digest for each eligible user and organization';
    public function handle(NotificationSourceWriter $writer, ProjectExecutionAccess $access): int
    {
        $date=$this->option('date')?:now('Asia/Tokyo')->toDateString();$count=0;
        OrganizationUser::query()->where('membership_status',OrganizationUser::STATUS_ACTIVE)->orderBy('id')->chunkById(100,function($memberships)use($writer,$access,$date,&$count){
            foreach($memberships as $membership){$user=User::find($membership->user_id);if(!$user?->is_active)continue;
                $executionItems=ActionExecution::query()->with('task.project')->where('organization_id',$membership->organization_id)
                    ->where('planned_assignee_id',$user->id)->whereIn('status',[ActionExecution::STATUS_PLANNED,ActionExecution::STATUS_MISSED])
                    ->whereDate('scheduled_date','<=',$date)->get()->filter(fn($execution)=>$execution->task?->project&&$access->canRead($user,$execution->task->project))->count();
                $directItems=Task::query()->with('project')->where('organization_id',$membership->organization_id)->where('assigned_to',$user->id)
                    ->whereDoesntHave('runSetting')->whereIn('status',[Task::STATUS_TODO,Task::STATUS_IN_PROGRESS])
                    ->whereNotNull('due_date')->whereDate('due_date','<=',$date)->get()->filter(fn($action)=>$access->canRead($user,$action->project))->count();
                $reviewItems=Task::query()->with('project')->where('organization_id',$membership->organization_id)->where('reviewer_user_id',$user->id)
                    ->where('status',Task::STATUS_REVIEW_PENDING)->get()->filter(fn($action)=>$access->canRead($user,$action->project))->count();
                $items=$executionItems+$directItems+$reviewItems;
                if($writer->todayDigest($membership->organization_id,$user,$items,$date))$count++;
            }
        });
        $this->line(json_encode(['date'=>$date,'created_or_existing'=>$count]));return self::SUCCESS;
    }
}
