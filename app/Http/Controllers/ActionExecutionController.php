<?php

namespace App\Http\Controllers;

use App\Models\ActionExecution;
use App\Models\ActionRunSetting;
use App\Models\Project;
use App\Models\Task;
use App\Services\ActionExecution\ActionExecutionAccess;
use App\Services\ActionExecution\ActionExecutionWriter;
use App\Services\ActionExecution\ActionDraftAssistant;
use App\Services\ProjectExecution\ProjectExecutionAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ActionExecutionController extends Controller
{
    public function today(Request $request, ProjectExecutionAccess $projectAccess): View
    {
        $company = $request->attributes->get('currentCompany');
        $user = $request->user();
        $today = now(config('app.timezone'))->toDateString();
        $executions = ActionExecution::query()->where('organization_id', $company->id)
            ->whereHas('task', fn ($query) => $query->where('assigned_to', $user->id))
            ->whereIn('status', [ActionExecution::STATUS_PLANNED, ActionExecution::STATUS_MISSED])
            ->with(['task.project', 'task.runSetting'])->orderBy('scheduled_date')->get()
            ->filter(fn ($execution) => $projectAccess->activeExplicitMember($user, $execution->task->project) !== null);
        $singleActions = Task::query()->where('organization_id', $company->id)->where('assigned_to', $user->id)
            ->whereIn('status', [Task::STATUS_TODO, Task::STATUS_IN_PROGRESS])->whereDoesntHave('runSetting')
            ->whereNotNull('due_date')->with('project')->get()->filter(fn ($task) => $projectAccess->activeExplicitMember($user, $task->project) !== null);
        $reviews = Task::query()->where('organization_id', $company->id)->where('reviewer_user_id', $user->id)
            ->where('status', Task::STATUS_REVIEW_PENDING)->whereNotNull('review_requested_at')->with('project')->get()
            ->filter(fn ($task) => $projectAccess->activeExplicitMember($user, $task->project) !== null);
        $history = ActionExecution::query()->where('organization_id', $company->id)->where('planned_assignee_id', $user->id)
            ->whereIn('status', [ActionExecution::STATUS_COMPLETED, ActionExecution::STATUS_MISSED, ActionExecution::STATUS_SKIPPED])
            ->where('scheduled_date', '>=', now()->startOfMonth()->toDateString())->with('task.project')->orderBy('scheduled_date')->get()
            ->filter(fn ($execution) => $projectAccess->activeExplicitMember($user, $execution->task->project) !== null);
        $completed = $history->where('status', ActionExecution::STATUS_COMPLETED)->count();
        $missed = $history->where('status', ActionExecution::STATUS_MISSED)->count();
        return view('action-executions.today', [
            'company' => $company, 'today' => $today,
            'todayExecutions' => $executions->where('status', ActionExecution::STATUS_PLANNED)->filter(fn ($e) => $e->window_starts_at_utc->lte(now('UTC')) && $e->window_ends_at_utc->gt(now('UTC'))),
            'todaySingles' => $singleActions->where('due_date', $today), 'overdueSingles' => $singleActions->filter(fn ($task) => $task->due_date->lt($today)),
            'reviews' => $reviews, 'history' => $history,
            'rate' => ($completed + $missed) ? round($completed / ($completed + $missed) * 100, 1) : null,
            'regularCount' => $history->where('origin', 'regular')->count(), 'retryCount' => $history->where('origin', 'retry')->count(),
        ]);
    }

    public function refresh(Request $request, ActionExecutionWriter $writer): RedirectResponse
    {
        $company = $request->attributes->get('currentCompany');
        $result = $writer->catchUp($company->id);
        return back()->with('status', "予定を更新しました（生成 {$result['generated']} / 未実施確定 {$result['missed']}）。");
    }

    public function show(Request $request, Project $project, Task $action, ActionExecutionAccess $access): View
    {
        $this->assertAction($request, $project, $action);
        abort_unless($access->canRead($request->user(), $action), 403);
        $action->load(['runSetting.currentRevision', 'executions' => fn ($q) => $q->latest('scheduled_date')->with('events')]);
        return view('action-executions.show', ['company' => $request->attributes->get('currentCompany'), 'project' => $project, 'action' => $action, 'canConfigure' => $access->canConfigure($request->user(), $action)]);
    }

    public function configure(Request $request, Project $project, Task $action, ActionExecutionWriter $writer): RedirectResponse
    {
        $this->assertAction($request, $project, $action);
        $data = $request->validate([
            'project_version' => ['required', 'integer'], 'run_type' => ['required', Rule::in(['one_time', 'continuous'])],
            'category' => ['required', Rule::in(['task', 'communication', 'check', 'follow_up', 'other'])],
            'frequency' => ['required', Rule::in(['daily', 'weekday', 'weekly', 'monthly', 'custom'])], 'interval' => ['required', 'integer', 'min:1', 'max:365'],
            'weekdays' => ['nullable', 'array'], 'weekdays.*' => ['integer', 'between:1,7'], 'month_day' => ['nullable', 'integer', 'between:1,31'], 'month_end' => ['nullable', 'boolean'],
            'execution_rule' => ['required', Rule::in(['on_date', 'by_date', 'within_window'])], 'window_days_before' => ['nullable', 'integer', 'between:0,365'],
            'starts_on' => ['required', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'], 'count_limit' => ['nullable', 'integer', 'between:1,10000'],
            'communication_target' => ['nullable', 'string', 'max:500'], 'communication_draft' => ['nullable', 'string', 'max:10000'], 'communication_time' => ['nullable', 'date_format:H:i'], 'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $writer->configure($request->user(), $action, $data, (int) $data['project_version']);
        return back()->with('status', '実施予定を保存しました。予約保存は外部送信予約ではありません。');
    }

    public function pause(Request $request, Project $project, Task $action, ActionExecutionWriter $writer): RedirectResponse
    {
        $this->assertAction($request, $project, $action);
        $data = $request->validate(['project_version' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:1000']]);
        $writer->pause($request->user(), $action, $data['reason'], (int) $data['project_version']);
        return back()->with('status', '継続予定を停止し、未来の予定を理由付きで見送りました。');
    }

    public function resume(Request $request, Project $project, Task $action, ActionExecutionWriter $writer): RedirectResponse
    {
        $this->assertAction($request, $project, $action);
        $data = $request->validate(['project_version' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:1000']]);
        $writer->resume($request->user(), $action, $data['reason'], (int) $data['project_version']);
        return back()->with('status', '新しい予定revisionとして今日以降を再開しました。');
    }
    public function suggestDraft(Request $request, Project $project, Task $action, ActionExecutionAccess $access, ActionDraftAssistant $assistant): RedirectResponse
    {
        $this->assertAction($request, $project, $action);
        abort_unless($access->canConfigure($request->user(), $action), 403);
        $data = $request->validate(['communication_target' => ['required', 'string', 'max:500'], 'draft_instruction' => ['required', 'string', 'max:2000']]);
        $draft = $assistant->suggest($request->user(), $action, $data['communication_target'], $data['draft_instruction']);
        return back()->withInput()->with('ai_draft_suggestion', $draft);
    }

    public function complete(Request $request, ActionExecution $execution, ActionExecutionWriter $writer): RedirectResponse
    {
        $this->assertExecutionCompany($request, $execution);
        $data = $request->validate(['operation_id' => ['required', 'uuid'], 'performed_at' => ['nullable', 'date'], 'memo' => ['nullable', 'string', 'max:3000']]);
        $result = $writer->complete($request->user(), $execution, $data);
        if ($result->status === ActionExecution::STATUS_MISSED) {
            return back()->withErrors(['execution' => '実施期限を過ぎたため未実施として記録しました。再実施を追加してください。']);
        }
        return back()->with('status', '実施を記録しました。');
    }

    public function skip(Request $request, ActionExecution $execution, ActionExecutionWriter $writer): RedirectResponse
    {
        $this->assertExecutionCompany($request, $execution);
        $data = $request->validate(['operation_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:1000']]);
        $result = $writer->skip($request->user(), $execution, $data['reason'], $data['operation_id']);
        if ($result->status === ActionExecution::STATUS_MISSED) {
            return back()->withErrors(['execution' => '実施期限を過ぎているため、見送りに変更せず未実施を保持しました。']);
        }
        return back()->with('status', 'この予定を理由付きで見送りました。');
    }

    public function retry(Request $request, ActionExecution $execution, ActionExecutionWriter $writer): RedirectResponse
    {
        $this->assertExecutionCompany($request, $execution);
        $data = $request->validate(['operation_id' => ['required', 'uuid']]);
        $writer->retry($request->user(), $execution, $data['operation_id']);
        return back()->with('status', '今日の再実施を追加しました。元の未実施記録は保持されます。');
    }

    private function assertAction(Request $request, Project $project, Task $action): void
    {
        abort_unless($project->organization_id === $request->attributes->get('currentCompany')->id && $action->project_id === $project->id, 404);
    }
    private function assertExecutionCompany(Request $request, ActionExecution $execution): void
    {
        abort_unless($execution->organization_id === $request->attributes->get('currentCompany')->id, 404);
    }
}
