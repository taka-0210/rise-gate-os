<?php

namespace Tests\Feature;

use App\Models\CompanyNotification;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\OrganizationNotificationCalendarDate;
use App\Models\OrganizationNotificationPolicy;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Models\Workspace;
use App\Services\Notification\NotificationDeliveryProcessor;
use App\Services\Notification\NotificationTiming;
use App\Services\Notification\NotificationVisibility;
use App\Services\ProjectExecution\ProjectExecutionWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class S10CdTqcC01CompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'http://localhost',
            'company_notifications.delivery_enabled' => true,
            'product_ux.organization_admission_enabled' => false,
        ]);
        app('url')->forceRootUrl('http://localhost');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_center_body_and_badge_wait_until_the_eligible_boundary(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-27 23:00:00', 'UTC')); // Monday 08:00 JST
        [$owner, $assignee, $organization, $project] = $this->fixture();
        $this->policy($organization, [
            '1' => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
            '2' => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
        ]);

        app(ProjectExecutionWriter::class)->createAction($owner, $project->fresh(), [
            'title' => 'Deferred center item',
            'done_condition' => 'Handled',
            'assigned_to' => $assignee->id,
            'notification_timing' => 'now',
        ], $project->fresh()->plan_version);

        $notification = CompanyNotification::firstOrFail();
        $this->assertSame('2026-09-28 00:00:00', $notification->eligible_at_utc->format('Y-m-d H:i:s'));
        $this->assertSame($notification->eligible_at_utc->timestamp, $notification->content_visible_at_utc->timestamp);

        $this->actingAs($assignee)->withSession($this->companySession($organization))
            ->get(route('notifications.index'))->assertOk()->assertDontSee('Deferred center item');
        $this->actingAs($assignee)->withSession($this->companySession($organization))
            ->get(route('company.home'))->assertOk()->assertDontSee('未読 1件');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-28 00:00:00', 'UTC'));
        $this->actingAs($assignee)->withSession($this->companySession($organization))
            ->get(route('notifications.index'))->assertOk()->assertSee('Deferred center item');
        $this->actingAs($assignee)->withSession($this->companySession($organization))
            ->get(route('company.home'))->assertOk()->assertSee('未読 1件');
    }

    public function test_timing_treats_specified_as_earliest_and_handles_windows_exceptions_and_empty_policy(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-27 23:00:00', 'UTC')); // Monday 08:00 JST
        [$owner, , $organization] = $this->fixture();
        $policy = $this->policy($organization, [
            '1' => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
            '2' => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
            '3' => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
        ]);
        OrganizationNotificationCalendarDate::create([
            'organization_id' => $organization->id,
            'calendar_date' => '2026-09-29',
            'kind' => OrganizationNotificationCalendarDate::HOLIDAY,
        ]);
        UserNotificationPreference::create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'quiet_starts_at' => '09:00',
            'quiet_ends_at' => '10:00',
        ]);

        $this->assertSame(
            '2026-09-30 01:00:00',
            app(NotificationTiming::class)->eligibleAt($organization->id, 'specified', '2026-09-29 10:00', $owner->id)?->format('Y-m-d H:i:s'),
        );

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-28 01:00:00', 'UTC')); // Monday 10:00 JST
        $this->assertSame(
            '2026-09-28 01:00:00',
            app(NotificationTiming::class)->eligibleAt($organization->id, 'next_window', null, $owner->id)?->format('Y-m-d H:i:s'),
        );

        $policy->update(['weekday_windows' => []]);
        $this->assertNull(app(NotificationTiming::class)->eligibleAt($organization->id, 'next_window', null, $owner->id));

        $policy->update(['is_confirmed' => false]);
        $this->assertNull(app(NotificationTiming::class)->eligibleAt($organization->id, 'now', null, $owner->id));
        $policy->update(['is_confirmed' => true]);

        OrganizationNotificationCalendarDate::create([
            'organization_id' => $organization->id,
            'calendar_date' => '2026-10-01',
            'kind' => OrganizationNotificationCalendarDate::WORKING_EXCEPTION,
        ]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 23:00:00', 'UTC')); // Thursday 08:00 JST
        $this->assertSame(
            '2026-10-01 01:00:00',
            app(NotificationTiming::class)->eligibleAt($organization->id, 'now', null, $owner->id)?->format('Y-m-d H:i:s'),
        );
    }

    public function test_policy_change_is_rechecked_before_delivery_without_consuming_an_attempt(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-28 01:00:00', 'UTC')); // Monday 10:00 JST
        [$owner, $assignee, $organization, $project] = $this->fixture();
        $policy = $this->policy($organization, [
            '1' => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
            '2' => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
        ]);
        app(ProjectExecutionWriter::class)->createAction($owner, $project->fresh(), [
            'title' => 'Policy changed item',
            'done_condition' => 'Handled',
            'assigned_to' => $assignee->id,
        ], $project->fresh()->plan_version);

        $delivery = NotificationDelivery::firstOrFail();
        $policy->update(['weekday_windows' => [
            '2' => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
        ]]);

        $this->assertSame(
            ['processed' => 0, 'delivered' => 0, 'failed' => 0, 'disabled' => false],
            app(NotificationDeliveryProcessor::class)->run(10),
        );
        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertSame(0, $delivery->fresh()->attempt_count);
        $this->assertSame('2026-09-29 00:00:00', $delivery->fresh()->available_at_utc->format('Y-m-d H:i:s'));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-29 00:00:00', 'UTC'));
        $this->assertSame(1, app(NotificationDeliveryProcessor::class)->run(10)['delivered']);
    }

    public function test_source_permission_loss_removes_index_body_badge_read_open_and_cancels_delivery(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-28 01:00:00', 'UTC'));
        [$owner, $assignee, $organization, $project] = $this->fixture();
        $this->policy($organization, [
            '1' => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
        ]);
        $action = app(ProjectExecutionWriter::class)->createAction($owner, $project->fresh(), [
            'title' => 'Revoked source item',
            'done_condition' => 'Handled',
            'assigned_to' => $assignee->id,
        ], $project->fresh()->plan_version);
        $notification = CompanyNotification::firstOrFail();
        $action->update(['assigned_to' => $owner->id]);

        $this->actingAs($assignee)->withSession($this->companySession($organization))
            ->get(route('notifications.index'))->assertOk()->assertDontSee('Revoked source item');
        $this->actingAs($assignee)->withSession($this->companySession($organization))
            ->get(route('company.home'))->assertOk()->assertDontSee('未読 1件');
        $this->actingAs($assignee)->withSession($this->companySession($organization))
            ->post(route('notifications.read', $notification))->assertNotFound();
        $this->actingAs($assignee)->withSession($this->companySession($organization))
            ->get(route('notifications.open', $notification))->assertNotFound();

        app(NotificationDeliveryProcessor::class)->run(10);
        $this->assertSame('cancelled', NotificationDelivery::firstOrFail()->status);
        $this->assertNotNull($notification->fresh()->cancelled_at_utc);
    }

    public function test_non_recipient_cannot_list_read_or_open_another_users_notification(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-28 01:00:00', 'UTC'));
        [$owner, $assignee, $organization, $project] = $this->fixture();
        $this->policy($organization, [
            '1' => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
        ]);
        app(ProjectExecutionWriter::class)->createAction($owner, $project->fresh(), [
            'title' => 'Recipient private item',
            'done_condition' => 'Handled',
            'assigned_to' => $assignee->id,
        ], $project->fresh()->plan_version);
        $notification = CompanyNotification::firstOrFail();

        $this->actingAs($owner)->withSession($this->companySession($organization))
            ->get(route('notifications.index'))->assertOk()->assertDontSee('Recipient private item');
        $this->actingAs($owner)->withSession($this->companySession($organization))
            ->get(route('company.home'))->assertOk()->assertDontSee('譛ｪ隱ｭ 1莉ｶ');
        $this->actingAs($owner)->withSession($this->companySession($organization))
            ->post(route('notifications.read', $notification))->assertNotFound();
        $this->actingAs($owner)->withSession($this->companySession($organization))
            ->get(route('notifications.open', $notification))->assertNotFound();
    }

    #[DataProvider('authorizationBoundaryProvider')]
    public function test_membership_and_account_generation_boundaries_fail_closed(string $boundary): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-28 01:00:00', 'UTC'));
        [$owner, $assignee, $organization, $project] = $this->fixture();
        $this->policy($organization, [
            '1' => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
        ]);
        app(ProjectExecutionWriter::class)->createAction($owner, $project->fresh(), [
            'title' => 'Authorization boundary item',
            'done_condition' => 'Handled',
            'assigned_to' => $assignee->id,
        ], $project->fresh()->plan_version);
        $notification = CompanyNotification::firstOrFail();
        $membership = OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $assignee->id)
            ->firstOrFail();

        match ($boundary) {
            'suspended' => $membership->update(['membership_status' => OrganizationUser::STATUS_SUSPENDED]),
            'left' => $membership->update(['membership_status' => OrganizationUser::STATUS_LEFT]),
            'inactive' => $assignee->update(['is_active' => false]),
            'epoch' => $membership->increment('access_epoch'),
            'credential' => $assignee->increment('credential_generation'),
        };

        $this->assertFalse(app(NotificationVisibility::class)->isVisible($notification->fresh('recipient')));
        app(NotificationDeliveryProcessor::class)->run(10);
        $this->assertSame('cancelled', NotificationDelivery::firstOrFail()->status);
        $this->assertNotNull($notification->fresh()->cancelled_at_utc);
    }

    public static function authorizationBoundaryProvider(): array
    {
        return [
            'suspended membership' => ['suspended'],
            'left membership' => ['left'],
            'global inactive' => ['inactive'],
            'stale membership epoch' => ['epoch'],
            'stale credential generation' => ['credential'],
        ];
    }

    private function policy(Organization $organization, array $windows): OrganizationNotificationPolicy
    {
        return OrganizationNotificationPolicy::create([
            'organization_id' => $organization->id,
            'is_confirmed' => true,
            'timezone' => 'Asia/Tokyo',
            'weekday_windows' => $windows,
        ]);
    }

    private function companySession(Organization $organization): array
    {
        return [
            'access_mode' => 'workspace',
            'current_company_id' => $organization->id,
            'current_company_access_epoch' => 1,
            'credential_generation' => 1,
        ];
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $assignee = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::create(['name' => 'C01 Org', 'slug' => 'c01-'.uniqid()]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'owner_user_id' => $owner->id,
            'name' => 'C01 Workspace',
            'slug' => 'c01-workspace-'.uniqid(),
            'status' => Workspace::STATUS_ACTIVE,
        ]);
        foreach ([[$owner, 'owner'], [$assignee, 'member']] as [$user, $role]) {
            $organization->users()->attach($user->id, [
                'role' => $role,
                'organization_role' => $role,
                'membership_status' => 'active',
                'access_epoch' => 1,
                'joined_at' => now(),
            ]);
            $workspace->users()->attach($user->id, ['role' => $role, 'joined_at' => now()]);
            ProductAccountEligibility::create([
                'user_id' => $user->id,
                'mode' => ProductAccountEligibility::MODE_SINGLE,
                'product_organization_id' => $organization->id,
                'classification_version' => 'cd-tqc-c01',
                'classified_at' => now(),
                'evidence_ref' => 'synthetic-c01',
            ]);
        }
        $writer = app(ProjectExecutionWriter::class);
        $project = $writer->createProject($owner, $workspace, [
            'name' => 'C01 Project',
            'purpose' => 'Verify compatibility',
            'expected_outcome' => 'Closed S10 contracts hold',
        ]);
        $writer->addMember($owner, $project->fresh(), $assignee, $workspace, ['member'], $project->fresh()->plan_version);

        return [$owner, $assignee, $organization, $project];
    }
}
