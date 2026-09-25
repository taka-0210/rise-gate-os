<?php

namespace App\Services\ActionExecution;

use App\Models\ActionExecution;
use App\Models\ActionExecutionEvent;
use App\Models\ActionRunSetting;
use App\Models\ActionScheduleRevision;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectExecution\ProjectExecutionWriter as ScopeEightWriter;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ActionExecutionWriter
{
    public function __construct(
        private readonly ActionExecutionAccess $access,
        private readonly ActionRecurrence $recurrence,
        private readonly ScopeEightWriter $scopeEight,
    ) {}

    public function configure(User $actor, Task $task, array $data, int $expectedProjectVersion): ActionRunSetting
    {
        return DB::transaction(function () use ($actor, $task, $data, $expectedProjectVersion): ActionRunSetting {
            $project = $this->lockProject($task->project, $expectedProjectVersion);
            $task = Task::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($task->id);
            $this->assertOperable($task);
            if (! $this->access->canConfigure($actor, $task)) throw new AuthorizationException;

            $setting = ActionRunSetting::query()->where('task_id', $task->id)->lockForUpdate()->first();
            $changing = $setting?->current_revision_id !== null;
            if ($changing && blank($data['reason'] ?? null)) {
                throw ValidationException::withMessages(['reason' => '予定変更の理由を入力してください。']);
            }
            $setting ??= new ActionRunSetting(['task_id' => $task->id, 'organization_id' => $task->organization_id, 'project_id' => $task->project_id]);
            $setting->fill(Arr::only($data, ['run_type', 'category', 'communication_target', 'communication_draft', 'communication_time']));
            $setting->control_status = ActionRunSetting::STATUS_ACTIVE;
            $setting->row_version = ((int) $setting->row_version) + 1;
            $setting->save();

            if ($changing) {
                $future = ActionExecution::query()->where('task_id', $task->id)->where('status', ActionExecution::STATUS_PLANNED)
                    ->where('window_ends_at_utc', '>', now('UTC'))->lockForUpdate()->get();
                foreach ($future as $execution) {
                    $this->resolve($execution, ActionExecution::STATUS_SKIPPED, $actor, (string) $data['reason'], (string) Str::uuid());
                }
            }

            $revision = ActionScheduleRevision::create([
                ...Arr::only($data, ['frequency', 'interval', 'weekdays', 'month_day', 'month_end', 'execution_rule', 'window_days_before', 'starts_on', 'ends_on', 'count_limit']),
                'action_run_setting_id' => $setting->id,
                'task_id' => $task->id,
                'organization_id' => $task->organization_id,
                'project_id' => $task->project_id,
                'revision_number' => ((int) $setting->revisions()->max('revision_number')) + 1,
                'effective_from' => max($data['starts_on'], now(config('app.timezone'))->toDateString()),
                'timezone' => config('app.timezone'),
                'created_by_user_id' => $actor->id,
                'reason' => $data['reason'] ?? null,
            ]);
            $setting->update(['current_revision_id' => $revision->id]);
            $project->increment('plan_version');
            $this->generateRevision($revision, CarbonImmutable::now($revision->timezone)->addDays(31));
            return $setting->fresh(['currentRevision']);
        }, 3);
    }

    public function generateRevision(ActionScheduleRevision $revision, CarbonImmutable $through): int
    {
        $setting = $revision->setting;
        if ($setting->control_status !== ActionRunSetting::STATUS_ACTIVE) return 0;
        $task = $revision->task()->with(['project', 'assignee'])->firstOrFail();
        if (! $task->assignee || ! $task->assignee->is_active || ! $this->access->canComplete($task->assignee, (new ActionExecution)->setRelation('task', $task))) {
            return 0;
        }
        $regularCount = ActionExecution::query()->where('schedule_revision_id', $revision->id)->where('origin', 'regular')->count();
        $remaining = $revision->count_limit !== null ? max(0, $revision->count_limit - $regularCount) : 500;
        if ($remaining === 0) return 0;
        $from = $revision->generated_through
            ? CarbonImmutable::parse($revision->generated_through, $revision->timezone)->addDay()
            : CarbonImmutable::parse($revision->effective_from, $revision->timezone);
        $through = $through->min($from->addDays(31));
        $created = 0;
        foreach ($this->recurrence->occurrences($revision, $from, $through, min(500, $remaining)) as $slot) {
            $key = "revision:{$revision->id}:{$slot['date']->toDateString()}";
            try {
                $execution = ActionExecution::query()->firstOrCreate(['slot_key' => $key], [
                    'task_id' => $revision->task_id, 'organization_id' => $revision->organization_id,
                    'project_id' => $revision->project_id, 'schedule_revision_id' => $revision->id,
                    'origin' => 'regular', 'scheduled_date' => $slot['date']->toDateString(),
                    'window_starts_at_utc' => $slot['start_utc'], 'window_ends_at_utc' => $slot['end_utc'],
                    'status' => ActionExecution::STATUS_PLANNED, 'planned_assignee_id' => $task->assigned_to,
                ]);
                if ($execution->wasRecentlyCreated) {
                    $this->event($execution, 'execution.planned', null, [], $execution->only(['status', 'scheduled_date', 'planned_assignee_id']), (string) Str::uuid(), null);
                    $created++;
                }
            } catch (QueryException $exception) {
                if (! str_contains(strtolower($exception->getMessage()), 'unique')) throw $exception;
            }
        }
        $revision->update(['generated_through' => $through->toDateString()]);
        return $created;
    }

    public function pause(User $actor, Task $task, string $reason, int $expectedProjectVersion): ActionRunSetting
    {
        return DB::transaction(function () use ($actor, $task, $reason, $expectedProjectVersion): ActionRunSetting {
            $project = $this->lockProject($task->project, $expectedProjectVersion);
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            if (! $this->access->canConfigure($actor, $task) || blank($reason)) throw new AuthorizationException;
            $setting = ActionRunSetting::query()->where('task_id', $task->id)->lockForUpdate()->firstOrFail();
            foreach (ActionExecution::query()->where('task_id', $task->id)->where('status', ActionExecution::STATUS_PLANNED)->where('window_ends_at_utc', '>', now('UTC'))->lockForUpdate()->get() as $execution) {
                $this->resolve($execution, ActionExecution::STATUS_SKIPPED, $actor, $reason, (string) Str::uuid());
            }
            $setting->update(['control_status' => ActionRunSetting::STATUS_PAUSED, 'row_version' => $setting->row_version + 1]);
            $project->increment('plan_version');
            return $setting->fresh();
        }, 3);
    }

    public function resume(User $actor, Task $task, string $reason, int $expectedProjectVersion): ActionRunSetting
    {
        return DB::transaction(function () use ($actor, $task, $reason, $expectedProjectVersion): ActionRunSetting {
            $project = $this->lockProject($task->project, $expectedProjectVersion);
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            if (! $this->access->canConfigure($actor, $task) || blank($reason)) throw new AuthorizationException;
            $setting = ActionRunSetting::query()->where('task_id', $task->id)->with('currentRevision')->lockForUpdate()->firstOrFail();
            if ($setting->control_status !== ActionRunSetting::STATUS_PAUSED) throw ValidationException::withMessages(['schedule' => '停止中の予定だけ再開できます。']);
            $old = $setting->currentRevision;
            $remainingCount = $old->count_limit === null
                ? null
                : max(0, $old->count_limit - ActionExecution::query()
                    ->where('schedule_revision_id', $old->id)
                    ->where('origin', 'regular')
                    ->count());
            $revision = ActionScheduleRevision::create([
                ...Arr::only($old->toArray(), ['frequency', 'interval', 'weekdays', 'month_day', 'month_end', 'execution_rule', 'window_days_before', 'ends_on', 'timezone']),
                'action_run_setting_id' => $setting->id, 'task_id' => $task->id, 'organization_id' => $task->organization_id, 'project_id' => $task->project_id,
                'revision_number' => ((int) $setting->revisions()->max('revision_number')) + 1, 'starts_on' => now($old->timezone)->toDateString(),
                'effective_from' => now($old->timezone)->toDateString(), 'count_limit' => $remainingCount,
                'created_by_user_id' => $actor->id, 'reason' => $reason,
            ]);
            $setting->update(['control_status' => ActionRunSetting::STATUS_ACTIVE, 'current_revision_id' => $revision->id, 'row_version' => $setting->row_version + 1]);
            $project->increment('plan_version');
            $this->generateRevision($revision, CarbonImmutable::now($revision->timezone)->addDays(31));
            return $setting->fresh('currentRevision');
        }, 3);
    }
    public function catchUp(?int $organizationId = null): array
    {
        $generated = 0; $missed = 0;
        ActionScheduleRevision::query()->whereHas('setting', fn ($q) => $q->where('control_status', ActionRunSetting::STATUS_ACTIVE))
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))->with(['setting', 'task'])->chunkById(100, function ($revisions) use (&$generated): void {
                foreach ($revisions as $revision) $generated += $this->generateRevision($revision, CarbonImmutable::now($revision->timezone)->addDays(31));
            });
        ActionExecution::query()->where('status', ActionExecution::STATUS_PLANNED)->where('window_ends_at_utc', '<=', now('UTC'))
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))->chunkById(100, function ($executions) use (&$missed): void {
                foreach ($executions as $execution) {
                    DB::transaction(function () use ($execution, &$missed): void {
                        $locked = ActionExecution::query()->lockForUpdate()->find($execution->id);
                        if ($locked?->status === ActionExecution::STATUS_PLANNED && $locked->window_ends_at_utc->lte(now('UTC'))) {
                            $this->resolve($locked, ActionExecution::STATUS_MISSED, null, 'window_elapsed', (string) Str::uuid());
                            $missed++;
                        }
                    }, 3);
                }
            });
        return compact('generated', 'missed');
    }

    public function complete(User $actor, ActionExecution $execution, array $data): ActionExecution
    {
        return DB::transaction(function () use ($actor, $execution, $data): ActionExecution {
            $project = Project::query()->lockForUpdate()->findOrFail($execution->project_id);
            $execution = ActionExecution::query()->lockForUpdate()->findOrFail($execution->id);
            if ($existing = $this->existingOperation($execution, $data['operation_id'])) return $existing;
            $task = Task::query()->lockForUpdate()->findOrFail($execution->task_id);
            if (! $this->access->canComplete($actor, $execution->setRelation('task', $task))) throw new AuthorizationException;
            $this->assertOperable($task);
            if ($execution->status !== ActionExecution::STATUS_PLANNED) throw ValidationException::withMessages(['execution' => '予定中の回だけ実施できます。']);
            $now = CarbonImmutable::now('UTC');
            if ($execution->window_ends_at_utc->lte($now)) {
                $this->resolve($execution, ActionExecution::STATUS_MISSED, null, 'window_elapsed', (string) Str::uuid());
                return $execution->fresh();
            }
            if ($execution->window_starts_at_utc->gt($now)) throw ValidationException::withMessages(['execution' => '現在は実施できません。']);
            $performed = CarbonImmutable::parse($data['performed_at'] ?? $now, config('app.timezone'))->utc();
            if ($performed->gt($now) || $performed->lt($execution->window_starts_at_utc) || $performed->gte($execution->window_ends_at_utc)) throw ValidationException::withMessages(['performed_at' => '実施日時は現在の実施期間内を指定してください。']);
            if ($task->status === Task::STATUS_TODO) $this->scopeEight->transitionAction($actor, $task, 'start', $project->plan_version);
            $before = $execution->only(['status', 'row_version']);
            $execution->update(['status' => ActionExecution::STATUS_COMPLETED, 'completed_by_user_id' => $actor->id, 'performed_at_utc' => $performed, 'reported_at_utc' => $now, 'resolved_at_utc' => $now, 'memo' => $data['memo'] ?? null, 'row_version' => $execution->row_version + 1]);
            $this->event($execution, 'execution.completed', $actor, $before, $execution->only(['status', 'row_version']), $data['operation_id'], $data['memo'] ?? null);
            if ($execution->task->runSetting?->run_type === ActionRunSetting::TYPE_ONE_TIME) $this->scopeEight->transitionAction($actor, $task->fresh(), 'complete', $project->fresh()->plan_version);
            return $execution->fresh();
        }, 3);
    }

    public function skip(User $actor, ActionExecution $execution, string $reason, string $operationId): ActionExecution
    {
        return DB::transaction(function () use ($actor, $execution, $reason, $operationId): ActionExecution {
            Project::query()->lockForUpdate()->findOrFail($execution->project_id);
            $execution = ActionExecution::query()->lockForUpdate()->findOrFail($execution->id);
            if ($existing = $this->existingOperation($execution, $operationId)) return $existing;
            if (! $this->access->canSkip($actor, $execution->load('task.project')) || blank($reason)) throw new AuthorizationException;
            if ($execution->status !== ActionExecution::STATUS_PLANNED) throw ValidationException::withMessages(['execution' => '予定中の回だけ見送れます。']);
            if ($execution->window_ends_at_utc->lte(now('UTC'))) {
                $this->resolve($execution, ActionExecution::STATUS_MISSED, null, 'window_elapsed', (string) Str::uuid());
                return $execution->fresh();
            }
            $this->resolve($execution, ActionExecution::STATUS_SKIPPED, $actor, $reason, $operationId);
            return $execution->fresh();
        }, 3);
    }

    public function retry(User $actor, ActionExecution $source, string $operationId): ActionExecution
    {
        return DB::transaction(function () use ($actor, $source, $operationId): ActionExecution {
            Project::query()->lockForUpdate()->findOrFail($source->project_id);
            $source = ActionExecution::query()->lockForUpdate()->findOrFail($source->id);
            if ($source->status !== ActionExecution::STATUS_MISSED || ! $this->access->canComplete($actor, $source->load('task.project'))) throw new AuthorizationException;
            $date = CarbonImmutable::now(config('app.timezone'))->startOfDay();
            $retry = ActionExecution::query()->firstOrCreate(['retry_key' => "source:{$source->id}:{$date->toDateString()}"], [
                'task_id' => $source->task_id, 'organization_id' => $source->organization_id, 'project_id' => $source->project_id,
                'source_execution_id' => $source->id, 'origin' => 'retry', 'slot_key' => "retry:{$source->id}:{$date->toDateString()}",
                'scheduled_date' => $date->toDateString(), 'window_starts_at_utc' => $date->utc(), 'window_ends_at_utc' => $date->addDay()->utc(),
                'status' => ActionExecution::STATUS_PLANNED, 'planned_assignee_id' => $actor->id,
            ]);
            if ($retry->wasRecentlyCreated) $this->event($retry, 'execution.retry_created', $actor, [], $retry->only(['status', 'source_execution_id', 'scheduled_date']), $operationId, 'human_requested_retry');
            return $retry;
        }, 3);
    }

    private function resolve(ActionExecution $execution, string $status, ?User $actor, string $reason, string $operationId): void
    {
        $before = $execution->only(['status', 'row_version']);
        $execution->update(['status' => $status, 'resolution_reason' => $reason, 'resolved_at_utc' => now('UTC'), 'row_version' => $execution->row_version + 1]);
        $this->event($execution, "execution.{$status}", $actor, $before, $execution->only(['status', 'row_version']), $operationId, $reason);
    }

    private function event(ActionExecution $execution, string $event, ?User $actor, array $before, array $after, string $operationId, ?string $reason): void
    {
        ActionExecutionEvent::create(['action_execution_id' => $execution->id, 'organization_id' => $execution->organization_id, 'project_id' => $execution->project_id, 'task_id' => $execution->task_id, 'operation_id' => $operationId, 'payload_fingerprint' => hash('sha256', json_encode([$event, $after, $reason])), 'event' => $event, 'actor_type' => $actor ? 'human' : 'system', 'actor_user_id' => $actor?->id, 'before_state' => $before, 'after_state' => $after, 'reason' => $reason, 'occurred_at_utc' => now('UTC')]);
    }

    private function existingOperation(ActionExecution $execution, string $operationId): ?ActionExecution
    {
        return ActionExecutionEvent::query()->where('action_execution_id', $execution->id)->where('operation_id', $operationId)->exists() ? $execution->fresh() : null;
    }

    private function assertOperable(Task $task): void
    {
        if (! in_array($task->status, [Task::STATUS_TODO, Task::STATUS_IN_PROGRESS], true)) throw ValidationException::withMessages(['action' => '未着手または進行中のActionだけ設定・実施できます。']);
    }

    private function lockProject(Project $project, int $expectedVersion): Project
    {
        $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
        if ((int) $locked->plan_version !== $expectedVersion) throw ValidationException::withMessages(['project_version' => 'Projectが更新されています。再読込してください。']);
        return $locked;
    }
}
