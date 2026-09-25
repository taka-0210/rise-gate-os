<?php

namespace Tests\Feature;

use App\Models\ActionExecution;
use App\Models\ActionScheduleRevision;
use App\Models\Organization;
use App\Models\ProjectPlanVersion;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Contracts\ActionDraftProvider;
use App\Services\ActionExecution\ActionDraftAssistant;
use App\Services\ActionExecution\ActionExecutionWriter;
use App\Services\ActionExecution\ActionRecurrence;
use App\Services\ProjectExecution\ProjectExecutionWriter;
use App\Services\ProjectPlanRestoreService;
use App\Services\ProjectPlanSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ScopeNineActionExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost', 'product_ux.organization_admission_enabled' => false]);
        app('url')->forceRootUrl('http://localhost');
    }

    public function test_monthly_by_date_is_one_window_and_month_end_is_deterministic(): void
    {
        $revision = new ActionScheduleRevision(['frequency' => 'monthly', 'interval' => 1, 'month_day' => 5, 'execution_rule' => 'by_date', 'starts_on' => '2028-01-01', 'effective_from' => '2028-01-01', 'timezone' => 'Asia/Tokyo']);
        $slots = app(ActionRecurrence::class)->occurrences($revision, CarbonImmutable::parse('2028-01-01'), CarbonImmutable::parse('2028-02-29'));
        $this->assertSame(['2028-01-05', '2028-02-05'], array_map(fn ($slot) => $slot['date']->toDateString(), $slots));
        $this->assertSame('2027-12-31 15:00:00', $slots[0]['start_utc']->format('Y-m-d H:i:s'));

        $revision->month_day = null; $revision->month_end = true; $revision->execution_rule = 'on_date';
        $slots = app(ActionRecurrence::class)->occurrences($revision, CarbonImmutable::parse('2028-01-01'), CarbonImmutable::parse('2028-02-29'));
        $this->assertSame(['2028-01-31', '2028-02-29'], array_map(fn ($slot) => $slot['date']->toDateString(), $slots));

        $revision = new ActionScheduleRevision(['frequency' => 'daily', 'interval' => 1, 'execution_rule' => 'within_window', 'window_days_before' => 2, 'starts_on' => '2028-01-05', 'effective_from' => '2028-01-05', 'timezone' => 'Asia/Tokyo']);
        $slot = app(ActionRecurrence::class)->occurrences($revision, CarbonImmutable::parse('2028-01-05'), CarbonImmutable::parse('2028-01-05'))[0];
        $this->assertSame('2028-01-02 15:00:00', $slot['start_utc']->format('Y-m-d H:i:s'));
        $this->assertSame('2028-01-05 15:00:00', $slot['end_utc']->format('Y-m-d H:i:s'));
    }

    public function test_all_frequency_variants_are_deterministic(): void
    {
        $cases = [
            ['daily', [], ['2028-01-01', '2028-01-03', '2028-01-05']],
            ['weekday', [], ['2028-01-03', '2028-01-04', '2028-01-05']],
            ['weekly', ['weekdays' => [1, 3]], ['2028-01-03', '2028-01-05', '2028-01-17']],
            ['monthly', ['month_day' => 15], ['2028-01-15', '2028-03-15']],
            ['custom', ['weekdays' => [2, 4]], ['2028-01-04', '2028-01-06', '2028-01-18']],
        ];

        foreach ($cases as [$frequency, $attributes, $expected]) {
            $revision = new ActionScheduleRevision(array_merge([
                'frequency' => $frequency,
                'interval' => 2,
                'execution_rule' => 'on_date',
                'starts_on' => '2028-01-01',
                'effective_from' => '2028-01-01',
                'timezone' => 'Asia/Tokyo',
            ], $attributes));

            $slots = app(ActionRecurrence::class)->occurrences(
                $revision,
                CarbonImmutable::parse('2028-01-01'),
                CarbonImmutable::parse('2028-03-31'),
                3,
            );

            $this->assertSame($expected, array_map(fn ($slot) => $slot['date']->toDateString(), $slots), $frequency);
        }
    }

    public function test_count_slots_are_not_refilled_and_retry_keeps_missed_source(): void
    {
        [$owner, , , $project, $action] = $this->fixture();
        $writer = app(ActionExecutionWriter::class);
        $setting = $writer->configure($owner, $action, $this->schedule(['count_limit' => 2]), $project->fresh()->plan_version);
        $executions = ActionExecution::query()->where('task_id', $action->id)->orderBy('scheduled_date')->get();
        $this->assertCount(2, $executions);
        $first = $executions->first();
        $first->update(['status' => ActionExecution::STATUS_MISSED, 'resolved_at_utc' => now('UTC')]);
        $retry = $writer->retry($owner, $first->fresh(), (string) Str::uuid());
        $this->assertSame('retry', $retry->origin);
        $this->assertSame($first->id, $retry->source_execution_id);
        $this->assertSame(ActionExecution::STATUS_MISSED, $first->fresh()->status);
        $this->assertDatabaseHas('action_execution_events', ['action_execution_id' => $retry->id, 'event' => 'execution.retry_created']);
        $writer->generateRevision($setting->currentRevision, CarbonImmutable::now()->addDays(31));
        $this->assertSame(2, ActionExecution::query()->where('schedule_revision_id', $setting->current_revision_id)->where('origin', 'regular')->count());
    }

    public function test_only_current_assignee_completes_and_continuous_parent_stays_in_progress(): void
    {
        [$owner, $organization, $workspace, $project, $action] = $this->fixture();
        $other = $this->join($organization, $workspace, 'other-s9@example.com');
        app(ProjectExecutionWriter::class)->addMember($owner, $project->fresh(), $other, $workspace, ['member'], $project->fresh()->plan_version);
        $writer = app(ActionExecutionWriter::class);
        $setting = $writer->configure($owner, $action, $this->schedule(['count_limit' => 2]), $project->fresh()->plan_version);
        $execution = ActionExecution::query()->where('schedule_revision_id', $setting->current_revision_id)->firstOrFail();
        try {
            $writer->complete($other, $execution, ['operation_id' => (string) Str::uuid()]);
            $this->fail('Non-assignee completion must fail.');
        } catch (AuthorizationException) {
            $this->assertSame(ActionExecution::STATUS_PLANNED, $execution->fresh()->status);
        }
        $operation = (string) Str::uuid();
        $writer->complete($owner, $execution->fresh(), ['operation_id' => $operation, 'memo' => 'done by assignee']);
        $writer->complete($owner, $execution->fresh(), ['operation_id' => $operation, 'memo' => 'done by assignee']);
        $this->assertSame(ActionExecution::STATUS_COMPLETED, $execution->fresh()->status);
        $this->assertSame(Task::STATUS_IN_PROGRESS, $action->fresh()->status);
        $this->assertSame(1, $execution->events()->where('event', 'execution.completed')->count());
    }

    public function test_skip_requires_assignee_or_active_project_owner_and_reason(): void
    {
        [$owner, $organization, $workspace, $project, $action] = $this->fixture();
        $reviewer = $this->join($organization, $workspace, 'reviewer-s9@example.com');
        app(ProjectExecutionWriter::class)->addMember($owner, $project->fresh(), $reviewer, $workspace, ['member'], $project->fresh()->plan_version);
        $writer = app(ActionExecutionWriter::class);
        $setting = $writer->configure($owner, $action, $this->schedule(['count_limit' => 1]), $project->fresh()->plan_version);
        $execution = ActionExecution::query()->where('schedule_revision_id', $setting->current_revision_id)->firstOrFail();
        $this->expectException(AuthorizationException::class);
        $writer->skip($reviewer, $execution, 'reviewer cannot skip', (string) Str::uuid());
    }

    public function test_snapshot_restore_fails_closed_after_scope_nine_setting(): void
    {
        [$owner, , , $project, $action] = $this->fixture();
        app(ActionExecutionWriter::class)->configure($owner, $action, $this->schedule(['count_limit' => 1]), $project->fresh()->plan_version);
        $snapshot = app(ProjectPlanSnapshotService::class)->capture($project->fresh());
        $version = ProjectPlanVersion::create(['project_id' => $project->id, 'version_number' => 1, 'version_type' => ProjectPlanVersion::TYPE_TIMELINE, 'title' => 'Before S9', 'previous_snapshot' => [], 'plan_snapshot' => $snapshot, 'created_by' => $owner->id]);
        $this->expectException(RuntimeException::class);
        app(ProjectPlanRestoreService::class)->preview($project, $version);
    }

    public function test_today_and_execution_detail_require_current_company_and_explicit_member(): void
    {
        [$owner, $organization, , $project, $action] = $this->fixture();
        app(ActionExecutionWriter::class)->configure($owner, $action, $this->schedule(['count_limit' => 1]), $project->fresh()->plan_version);
        $session = ['access_mode' => 'workspace', 'current_company_id' => $organization->id, 'current_company_access_epoch' => 1, 'credential_generation' => 1];
        $this->actingAs($owner)->withSession($session)->get(route('action-executions.today'))->assertOk()->assertSee('今日、動かすこと。')->assertSee($action->title);
        $this->actingAs($owner)->withSession($session)->get(route('action-executions.show', [$project, $action]))->assertOk()->assertSee('外部送信はしません');
    }

    public function test_pause_skips_future_slots_and_resume_uses_a_new_revision(): void
    {
        [$owner, , , $project, $action] = $this->fixture();
        $writer = app(ActionExecutionWriter::class);
        $setting = $writer->configure($owner, $action, $this->schedule(['count_limit' => 3]), $project->fresh()->plan_version);
        $oldRevision = $setting->current_revision_id;
        $writer->pause($owner, $action->fresh(), 'campaign paused', $project->fresh()->plan_version);
        $this->assertSame('paused', $setting->fresh()->control_status);
        $this->assertSame(3, ActionExecution::query()->where('schedule_revision_id', $oldRevision)->where('status', 'skipped')->count());
        $setting = $writer->resume($owner, $action->fresh(), 'campaign restarted', $project->fresh()->plan_version);
        $this->assertSame('active', $setting->control_status);
        $this->assertNotSame($oldRevision, $setting->current_revision_id);
        $this->assertSame(3, ActionExecution::query()->where('schedule_revision_id', $oldRevision)->count());
        $this->assertSame(0, $setting->currentRevision->count_limit);
        $this->assertSame(0, ActionExecution::query()->where('schedule_revision_id', $setting->current_revision_id)->count());
        $this->assertSame(3, ActionExecution::query()->where('task_id', $action->id)->where('origin', 'regular')->count());
    }

    public function test_expired_completion_becomes_missed_and_cannot_be_reclassified_as_skipped(): void
    {
        [$owner, , , $project, $action] = $this->fixture();
        $writer = app(ActionExecutionWriter::class);
        $setting = $writer->configure($owner, $action, $this->schedule(['count_limit' => 1]), $project->fresh()->plan_version);
        $execution = ActionExecution::query()->where('schedule_revision_id', $setting->current_revision_id)->firstOrFail();
        $execution->update(['window_starts_at_utc' => now('UTC')->subDays(2), 'window_ends_at_utc' => now('UTC')->subDay()]);

        $result = $writer->complete($owner, $execution->fresh(), ['operation_id' => (string) Str::uuid()]);

        $this->assertSame(ActionExecution::STATUS_MISSED, $result->status);
        $this->assertDatabaseHas('action_execution_events', ['action_execution_id' => $execution->id, 'event' => 'execution.missed']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $writer->skip($owner, $execution->fresh(), 'late skip is forbidden', (string) Str::uuid());
    }
    public function test_ai_draft_uses_only_approved_payload_and_never_writes_action(): void
    {
        [$owner, , $workspace, , $action] = $this->fixture();
        WorkspaceAiSetting::create(['workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'openai', 'allowed_data_categories' => ['project_metadata', 'tasks'], 'terms_version' => WorkspaceAiSetting::TERMS_VERSION, 'enabled_by' => $owner->id, 'enabled_at' => now()]);
        $fake = new class implements ActionDraftProvider {
            public array $payload = [];
            public function suggest(array $payload): string { $this->payload = $payload; return 'ご確認をお願いいたします。'; }
        };
        $this->app->instance(ActionDraftProvider::class, $fake);
        $before = $action->fresh()->toArray();
        $draft = app(ActionDraftAssistant::class)->suggest($owner, $action->fresh('project'), '取引先担当者', '丁寧で短く');
        $this->assertSame('ご確認をお願いいたします。', $draft);
        $this->assertSame(['target', 'action_title', 'done_condition', 'instruction'], array_keys($fake->payload));
        $this->assertSame('取引先担当者', $fake->payload['target']);
        $this->assertSame($before, $action->fresh()->toArray());
        $this->assertDatabaseHas('ai_audit_logs', ['project_id' => $action->project_id, 'event' => 'scope9.action_draft.suggested', 'succeeded' => true]);
        $this->assertDatabaseCount('action_executions', 0);
    }
    public function test_ai_failure_is_audited_without_saving_or_changing_business_data(): void
    {
        [$owner, , $workspace, , $action] = $this->fixture();
        WorkspaceAiSetting::create(['workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'fake', 'allowed_data_categories' => ['tasks'], 'terms_version' => WorkspaceAiSetting::TERMS_VERSION, 'enabled_by' => $owner->id, 'enabled_at' => now()]);
        $this->app->instance(ActionDraftProvider::class, new class implements ActionDraftProvider { public function suggest(array $payload): string { throw new RuntimeException('provider unavailable'); } });
        $before = $action->fresh()->toArray();
        try { app(ActionDraftAssistant::class)->suggest($owner, $action->fresh('project'), '取引先', '短く'); $this->fail('Provider failure must be visible.'); }
        catch (RuntimeException $exception) { $this->assertSame('provider unavailable', $exception->getMessage()); }
        $this->assertSame($before, $action->fresh()->toArray());
        $this->assertDatabaseHas('ai_audit_logs', ['project_id' => $action->project_id, 'event' => 'scope9.action_draft.suggested', 'succeeded' => false]);
        $this->assertDatabaseCount('action_executions', 0);
    }
    public function test_assignment_change_preserves_planned_responsibility_but_uses_current_assignee_permission(): void
    {
        [$owner, $organization, $workspace, $project, $action] = $this->fixture();
        $next = $this->join($organization, $workspace, 'next-s9@example.com');
        app(ProjectExecutionWriter::class)->addMember($owner, $project->fresh(), $next, $workspace, ['member'], $project->fresh()->plan_version);
        $setting = app(ActionExecutionWriter::class)->configure($owner, $action, $this->schedule(['count_limit' => 1]), $project->fresh()->plan_version);
        $execution = ActionExecution::query()->where('schedule_revision_id', $setting->current_revision_id)->firstOrFail();
        $action->update(['assigned_to' => $next->id]);
        $this->assertSame($owner->id, $execution->fresh()->planned_assignee_id);
        app(ActionExecutionWriter::class)->complete($next, $execution->fresh(), ['operation_id' => (string) Str::uuid()]);
        $this->assertSame($next->id, $execution->fresh()->completed_by_user_id);
        $this->assertSame($owner->id, $execution->fresh()->planned_assignee_id);
    }
    public function test_inactive_assignee_stops_future_generation_without_rewriting_existing_slots(): void
    {
        [$owner, , , $project, $action] = $this->fixture();
        $writer = app(ActionExecutionWriter::class);
        $setting = $writer->configure($owner, $action, $this->schedule(['count_limit' => null]), $project->fresh()->plan_version);
        $before = ActionExecution::query()->where('task_id', $action->id)->count();
        $owner->update(['is_active' => false]);
        $created = $writer->generateRevision($setting->currentRevision, CarbonImmutable::now()->addDays(90));
        $this->assertSame(0, $created);
        $this->assertSame($before, ActionExecution::query()->where('task_id', $action->id)->count());
    }
    private function fixture(): array
    {
        [$owner, $organization, $workspace] = $this->tenant('owner-s9-'.uniqid().'@example.com');
        $projectWriter = app(ProjectExecutionWriter::class);
        $project = $projectWriter->createProject($owner, $workspace, ['name' => 'Continuous work', 'purpose' => 'Keep moving', 'expected_outcome' => 'Visible execution']);
        $action = $projectWriter->createAction($owner, $project->fresh(), ['title' => 'Daily customer follow-up', 'done_condition' => 'Contact recorded', 'assigned_to' => $owner->id], $project->fresh()->plan_version);
        return [$owner, $organization, $workspace, $project, $action];
    }

    private function schedule(array $overrides = []): array
    {
        return array_merge(['run_type' => 'continuous', 'category' => 'task', 'frequency' => 'daily', 'interval' => 1, 'execution_rule' => 'on_date', 'window_days_before' => 0, 'starts_on' => now()->toDateString(), 'ends_on' => null, 'count_limit' => 2], $overrides);
    }

    private function tenant(string $email): array
    {
        $owner = User::factory()->create(['email' => $email]);
        $organization = Organization::create(['name' => 'Scope 9 Org', 'slug' => 'scope-9-'.uniqid()]);
        $workspace = Workspace::create(['organization_id' => $organization->id, 'owner_user_id' => $owner->id, 'name' => 'Execution', 'slug' => 'execution-'.uniqid(), 'status' => Workspace::STATUS_ACTIVE]);
        $organization->users()->attach($owner->id, ['role' => 'owner', 'organization_role' => 'owner', 'membership_status' => 'active', 'joined_at' => now()]);
        $workspace->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
        return [$owner, $organization, $workspace];
    }

    private function join(Organization $organization, Workspace $workspace, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $organization->users()->attach($user->id, ['role' => 'member', 'organization_role' => 'member', 'membership_status' => 'active', 'joined_at' => now()]);
        $workspace->users()->attach($user->id, ['role' => 'member', 'joined_at' => now()]);
        return $user;
    }
}
