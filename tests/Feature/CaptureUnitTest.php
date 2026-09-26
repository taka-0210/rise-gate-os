<?php

namespace Tests\Feature;

use App\Models\Capture;
use App\Models\CaptureActionRelation;
use App\Models\CompanyNotification;
use App\Models\Organization;
use App\Models\OrganizationNotificationPolicy;
use App\Models\ProductAccountEligibility;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Capture\CaptureAccess;
use App\Services\Capture\CaptureWriter;
use App\Services\Notification\NotificationAuthorization;
use App\Services\ProjectExecution\ProjectExecutionWriter;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CaptureUnitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['product_ux.organization_admission_enabled' => false]);
    }

    public function test_capture_needs_no_project_or_done_condition_and_retry_is_idempotent(): void
    {
        [$owner,$recipient,,$org] = $this->fixture();
        $op = (string) Str::uuid();
        $data = ['operation_id' => $op, 'type' => 'request', 'body' => 'Call supplier', 'recipient_user_id' => $recipient->id, 'notification_timing' => 'now'];
        $writer = app(CaptureWriter::class);
        $first = $writer->create($owner, $org, $data);
        $second = $writer->create($owner, $org, $data);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('captures', 1);
        $this->assertDatabaseCount('capture_events', 1);
        $this->assertDatabaseHas('captures', ['status' => 'open', 'version' => 1]);
    }

    public function test_only_creator_and_recipient_can_read_and_epoch_change_revokes_source(): void
    {
        [$owner,$recipient,$outsider,$org] = $this->fixture();
        $capture = $this->capture($owner, $recipient, $org);
        $access = app(CaptureAccess::class);
        $this->assertTrue($access->canRead($owner, $capture));
        $this->assertTrue($access->canRead($recipient, $capture));
        $this->assertFalse($access->canRead($outsider, $capture));
        $notification = CompanyNotification::firstOrFail();
        $this->assertTrue(app(NotificationAuthorization::class)->allowed($notification->load('recipient')));
        $recipient->organizationMemberships()->where('organization_id', $org->id)->increment('access_epoch');
        $this->assertFalse($access->canRead($recipient->fresh(), $capture->fresh()));
        $this->assertFalse(app(NotificationAuthorization::class)->allowed($notification->fresh('recipient')));
    }

    public function test_tell_later_acknowledge_closes_but_request_acknowledge_stays_open(): void
    {
        [$owner,$recipient,,$org] = $this->fixture();
        $writer = app(CaptureWriter::class);
        $tell = $this->capture($owner, $recipient, $org, 'tell_later');
        $writer->acknowledge($recipient, $tell, (string) Str::uuid(), 1);
        $this->assertSame('closed', $tell->fresh()->status);
        $request = $this->capture($owner, $recipient, $org, 'request');
        $writer->acknowledge($recipient, $request, (string) Str::uuid(), 1);
        $this->assertSame('open', $request->fresh()->status);
        $this->assertNotNull($request->fresh()->acknowledged_at_utc);
    }

    public function test_capture_notification_is_generic_and_self_capture_is_a_distinct_reminder(): void
    {
        [$owner,$recipient,,$org] = $this->fixture();
        $private = 'private body must not leave source';
        $capture = $this->capture($owner, $recipient, $org, 'request', $private);
        $notification = CompanyNotification::firstOrFail();
        $this->assertSame('Capture', $notification->source_type);
        $this->assertSame($capture->id, $notification->source_id);
        $this->assertSame('/company/captures/'.$capture->public_id, $notification->deep_link_path);
        $this->assertStringNotContainsString($private, $notification->title.$notification->body);
        $self = $this->capture($owner, $owner, $org, 'self');
        $this->assertDatabaseHas('company_notifications', ['source_type' => 'Capture', 'source_id' => $self->id, 'type' => 'capture_reminder']);
    }

    public function test_notification_read_does_not_acknowledge_and_terminal_capture_rejects_more_transitions(): void
    {
        [$owner,$recipient,,$org] = $this->fixture();
        CarbonImmutable::setTestNow('2026-09-28 01:00:00 UTC');
        OrganizationNotificationPolicy::create([
            'organization_id' => $org->id,
            'is_confirmed' => true,
            'timezone' => 'Asia/Tokyo',
            'weekday_windows' => array_fill(1, 7, ['enabled' => true, 'start' => '00:00', 'end' => '23:59']),
        ]);
        $capture = $this->capture($owner, $recipient, $org, 'tell_later');
        $notification = CompanyNotification::firstOrFail();
        $session = ['access_mode' => 'workspace', 'current_company_id' => $org->id, 'current_company_access_epoch' => 1, 'credential_generation' => 1];
        $this->actingAs($recipient)->withSession($session)->post(route('notifications.read', $notification))->assertRedirect();
        $this->assertNull($capture->fresh()->acknowledged_at_utc);
        app(CaptureWriter::class)->acknowledge($recipient, $capture, (string) Str::uuid(), 1);
        $this->assertSame(Capture::STATUS_CLOSED, $capture->fresh()->status);
        try {
            app(CaptureWriter::class)->close($owner, $capture->fresh(), (string) Str::uuid(), 2);
            $this->fail('A terminal Capture accepted another transition.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('capture_events', 2);
        }
        CarbonImmutable::setTestNow();
    }

    public function test_operation_payload_mismatch_and_body_boundary_fail_without_partial_write(): void
    {
        [$owner,$recipient,,$org] = $this->fixture();
        $writer = app(CaptureWriter::class);
        $operation = (string) Str::uuid();
        $base = ['operation_id' => $operation, 'type' => 'request', 'body' => 'Original', 'recipient_user_id' => $recipient->id, 'notification_timing' => 'now'];
        $writer->create($owner, $org, $base);
        foreach ([
            array_replace($base, ['body' => 'Changed']),
            ['operation_id' => (string) Str::uuid(), 'type' => 'request', 'body' => str_repeat('x', 4001), 'recipient_user_id' => $recipient->id, 'notification_timing' => 'now'],
        ] as $invalid) {
            try {
                $writer->create($owner, $org, $invalid);
                $this->fail('Invalid Capture input was accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('captures', 1);
            }
        }
        $this->assertDatabaseCount('capture_events', 1);
        $this->assertDatabaseCount('company_notifications', 1);
    }

    public function test_promotion_uses_existing_project_writer_and_is_one_to_one(): void
    {
        [$owner,$recipient,,$org,$project] = $this->fixture(true);
        $capture = $this->capture($owner, $recipient, $org);
        $op = (string) Str::uuid();
        $attributes = ['operation_id' => $op, 'title' => 'Promoted action', 'done_condition' => 'Completed', 'confirm_project_visibility' => true];
        $writer = app(CaptureWriter::class);
        $task = $writer->promote($owner, $capture, $project->fresh(), $attributes, 1, $project->fresh()->plan_version);
        $again = $writer->promote($owner, $capture->fresh(), $project->fresh(), $attributes, 2, $project->fresh()->plan_version);
        $this->assertSame($task->id, $again->id);
        $this->assertSame($recipient->id, $task->assigned_to);
        $this->assertSame($capture->body, $task->description);
        $this->assertSame('converted', $capture->fresh()->status);
        $this->assertSame(1, CaptureActionRelation::count());
        $this->assertSame(1, Task::where('title', 'Promoted action')->count());
        $this->assertDatabaseHas('project_execution_events', ['entity_type' => 'Task', 'entity_id' => $task->id, 'event' => 'action.created']);
    }

    public function test_cross_tenant_project_promotion_is_denied(): void
    {
        [$owner,$recipient,,$org] = $this->fixture();
        $capture = $this->capture($owner, $recipient, $org);
        [$otherOwner,,,,$otherProject] = $this->fixture(true);
        $this->expectException(AuthorizationException::class);
        app(CaptureWriter::class)->promote($owner, $capture, $otherProject, ['operation_id' => (string) Str::uuid(), 'title' => 'No', 'done_condition' => 'No', 'confirm_project_visibility' => true], 1, $otherProject->plan_version);
    }

    public function test_converted_capture_does_not_leak_relation_to_unrelated_admin(): void
    {
        [$owner,$recipient,$outsider,$org,$project] = $this->fixture(true);
        $capture = $this->capture($owner, $recipient, $org);
        $attributes = ['operation_id' => (string) Str::uuid(), 'title' => 'Private promoted action', 'done_condition' => 'Completed', 'confirm_project_visibility' => true];
        app(CaptureWriter::class)->promote($owner, $capture, $project->fresh(), $attributes, 1, $project->fresh()->plan_version);
        $this->expectException(AuthorizationException::class);
        app(CaptureWriter::class)->promote($outsider, $capture->fresh(), $project->fresh(), $attributes, 2, $project->fresh()->plan_version);
    }

    public function test_browser_contract_exposes_quick_capture_and_never_persists_body_client_side(): void
    {
        [$owner,$recipient,,$org] = $this->fixture();
        $session = ['access_mode' => 'workspace', 'current_company_id' => $org->id, 'current_company_access_epoch' => 1, 'credential_generation' => 1];
        $response = $this->actingAs($owner)->withSession($session)->get(route('captures.create'));
        $response->assertOk()->assertSee('COに預ける')->assertSee('capture-form')->assertSee('保存結果未確認')->assertSee('/company/capture-operations');
        $html = $response->getContent();
        $this->assertStringNotContainsString('localStorage', $html);
        $this->assertStringNotContainsString('indexedDB', $html);
        $this->assertStringNotContainsString('serviceWorker', $html);
        $worker = file_get_contents(public_path('service-worker.js'));
        $this->assertStringNotContainsString('/company/captures', $worker);
    }

    private function capture(User $owner, User $recipient, Organization $org, string $type = 'request', string $body = 'Private capture'): Capture
    {
        return app(CaptureWriter::class)->create($owner, $org, ['operation_id' => (string) Str::uuid(), 'type' => $type, 'body' => $body, 'recipient_user_id' => $recipient->id, 'notification_timing' => 'now']);
    }

    private function fixture(bool $project = false): array
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $outsider = User::factory()->create();
        $org = Organization::create(['name' => 'Capture Org', 'slug' => 'capture-'.Str::lower((string) Str::ulid())]);
        $workspace = Workspace::create(['organization_id' => $org->id, 'owner_user_id' => $owner->id, 'name' => 'Capture', 'slug' => 'capture-'.Str::lower((string) Str::ulid()), 'status' => Workspace::STATUS_ACTIVE]);
        foreach ([[$owner, 'owner'], [$recipient, 'member'], [$outsider, 'admin']] as [$user,$role]) {
            $org->users()->attach($user->id, ['role' => $role, 'organization_role' => $role, 'membership_status' => 'active', 'access_epoch' => 1, 'joined_at' => now()]);
            $workspace->users()->attach($user->id, ['role' => $role, 'joined_at' => now()]);
            ProductAccountEligibility::create(['user_id' => $user->id, 'mode' => ProductAccountEligibility::MODE_SINGLE, 'product_organization_id' => $org->id, 'classification_version' => 'capture-test', 'classified_at' => now(), 'evidence_ref' => 'capture-test']);
        }if (! $project) {
            return [$owner, $recipient, $outsider, $org];
        }$writer = app(ProjectExecutionWriter::class);
        $p = $writer->createProject($owner, $workspace, ['name' => 'Capture Project', 'purpose' => 'Capture', 'expected_outcome' => 'Action']);
        $writer->addMember($owner, $p->fresh(), $recipient, $workspace, ['member'], $p->fresh()->plan_version);

        return [$owner, $recipient, $outsider, $org, $p];
    }
}
