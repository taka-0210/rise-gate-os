<?php
namespace Tests\Feature;
use App\Models\CompanyNotification;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\OrganizationNotificationPolicy;
use App\Models\OrganizationNotificationCalendarDate;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Models\Workspace;
use App\Services\Notification\NotificationAuthorization;
use App\Services\Notification\NotificationDeliveryProcessor;
use App\Services\Notification\NotificationSourceWriter;
use App\Services\Notification\NotificationTiming;
use App\Services\ProjectExecution\ProjectExecutionWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScopeTenNotificationPwaTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url'=>'http://localhost','product_ux.organization_admission_enabled'=>false]);
        app('url')->forceRootUrl('http://localhost');
    }
    public function test_other_created_assignment_creates_one_notification_and_self_assignment_creates_none(): void
    {
        [$owner,$assignee,,,$project]=$this->fixture();$writer=app(ProjectExecutionWriter::class);
        $action=$writer->createAction($owner,$project->fresh(),['title'=>'Follow up','done_condition'=>'Recorded','assigned_to'=>$assignee->id],$project->fresh()->plan_version);
        $this->assertDatabaseHas('company_notifications',['type'=>'action_assigned','source_id'=>$action->id,'recipient_user_id'=>$assignee->id]);
        $this->assertSame(1,CompanyNotification::count());
        $writer->createAction($owner,$project->fresh(),['title'=>'Owner work','done_condition'=>'Done','assigned_to'=>$owner->id],$project->fresh()->plan_version);
        $this->assertSame(1,CompanyNotification::count());
    }
    public function test_review_attention_and_return_are_only_created_on_actual_transitions(): void
    {
        [$owner,$assignee,$reviewer,,$project]=$this->fixture();$writer=app(ProjectExecutionWriter::class);
        $action=$writer->createAction($owner,$project->fresh(),['title'=>'Review me','done_condition'=>'Accepted','assigned_to'=>$assignee->id,'reviewer_user_id'=>$reviewer->id],$project->fresh()->plan_version);
        $this->assertDatabaseMissing('company_notifications',['type'=>'review_attention']);
        $writer->transitionAction($assignee,$action->fresh(),'complete',$project->fresh()->plan_version);
        $this->assertDatabaseHas('company_notifications',['type'=>'review_attention','recipient_user_id'=>$reviewer->id]);
        $writer->transitionAction($reviewer,$action->fresh(),'reject',$project->fresh()->plan_version,'Please revise');
        $this->assertDatabaseHas('company_notifications',['type'=>'action_returned','recipient_user_id'=>$assignee->id]);
    }
    public function test_digest_is_zero_suppressed_and_unique_per_day(): void
    {
        [$owner,,,$organization]=$this->fixture();$writer=app(NotificationSourceWriter::class);
        $this->assertNull($writer->todayDigest($organization->id,$owner,0,'2026-09-26'));
        $first=$writer->todayDigest($organization->id,$owner,2,'2026-09-26');$second=$writer->todayDigest($organization->id,$owner,3,'2026-09-26');
        $this->assertSame($first->id,$second->id);$this->assertSame(1,CompanyNotification::where('type','today_digest')->count());
    }
    public function test_digest_command_counts_due_direct_action_without_hiding_the_work(): void
    {
        [$owner,$assignee,,,$project]=$this->fixture();
        app(ProjectExecutionWriter::class)->createAction($owner,$project->fresh(),[
            'title'=>'Due today','done_condition'=>'Handled','assigned_to'=>$assignee->id,'due_date'=>'2026-09-26',
        ],$project->fresh()->plan_version);
        $this->artisan('company-notifications:today-digests',['--date'=>'2026-09-26'])->assertSuccessful();
        $this->assertDatabaseHas('company_notifications',['type'=>'today_digest','recipient_user_id'=>$assignee->id]);
    }
    public function test_quiet_hours_defer_delivery(): void
    {
        [, ,,$organization]=$this->fixture();
        OrganizationNotificationPolicy::create(['organization_id'=>$organization->id,'is_confirmed'=>true,'timezone'=>'Asia/Tokyo','quiet_starts_at'=>'18:00','quiet_ends_at'=>'09:00',
            'weekday_windows'=>['1'=>['enabled'=>true,'start'=>'09:00','end'=>'18:00'],'2'=>['enabled'=>true,'start'=>'09:00','end'=>'18:00']]]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-28 12:00:00','UTC'));
        $this->assertSame('2026-09-29 00:00:00',app(NotificationTiming::class)->eligibleAt($organization->id,'now')->format('Y-m-d H:i:s'));
        CarbonImmutable::setTestNow();
    }
    public function test_explicit_calendar_and_personal_quiet_only_narrow_delivery(): void
    {
        [$owner,,,$organization]=$this->fixture();
        OrganizationNotificationPolicy::create(['organization_id'=>$organization->id,'is_confirmed'=>true,'timezone'=>'Asia/Tokyo',
            'weekday_windows'=>['1'=>['enabled'=>true,'start'=>'09:00','end'=>'18:00'],'2'=>['enabled'=>true,'start'=>'09:00','end'=>'18:00'],'3'=>['enabled'=>true,'start'=>'09:00','end'=>'18:00']]]);
        OrganizationNotificationCalendarDate::create(['organization_id'=>$organization->id,'calendar_date'=>'2026-09-29','kind'=>'holiday']);
        UserNotificationPreference::create(['organization_id'=>$organization->id,'user_id'=>$owner->id,'quiet_starts_at'=>'09:00','quiet_ends_at'=>'10:00']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-28 12:00:00','UTC'));
        $this->assertSame('2026-09-30 01:00:00',app(NotificationTiming::class)->eligibleAt($organization->id,'next_window',null,$owner->id)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30 01:00:00',app(NotificationTiming::class)->eligibleAt($organization->id,'specified','2026-09-30 10:00',$owner->id)->format('Y-m-d H:i:s'));
        CarbonImmutable::setTestNow();
    }
    public function test_delivery_reauthorization_cancels_after_epoch_change(): void
    {
        [$owner,$assignee,,,$project]=$this->fixture();
        app(ProjectExecutionWriter::class)->createAction($owner,$project->fresh(),['title'=>'Epoch work','done_condition'=>'Done','assigned_to'=>$assignee->id],$project->fresh()->plan_version);
        $delivery=NotificationDelivery::firstOrFail();$assignee->organizationMemberships()->where('organization_id',$project->organization_id)->increment('access_epoch');
        config(['company_notifications.delivery_enabled'=>true]);app(NotificationDeliveryProcessor::class)->run(10);
        $this->assertSame('cancelled',$delivery->fresh()->status);$this->assertDatabaseHas('notification_delivery_attempts',['notification_delivery_id'=>$delivery->id,'reason_code'=>'authorization_revoked']);
    }
    public function test_delivery_with_an_active_lease_is_not_claimed_again(): void
    {
        [$owner,$assignee,,,$project]=$this->fixture();
        app(ProjectExecutionWriter::class)->createAction($owner,$project->fresh(),['title'=>'Leased work','done_condition'=>'Done','assigned_to'=>$assignee->id],$project->fresh()->plan_version);
        $delivery=NotificationDelivery::firstOrFail();
        $delivery->update(['lease_token'=>'existing-worker','leased_until_utc'=>now('UTC')->addMinutes(5)]);
        config(['company_notifications.delivery_enabled'=>true]);

        $this->assertSame(['processed'=>0,'delivered'=>0,'failed'=>0,'disabled'=>false],app(NotificationDeliveryProcessor::class)->run(10));
        $this->assertSame('pending',$delivery->fresh()->status);
        $this->assertSame(0,$delivery->fresh()->attempt_count);
        $this->assertDatabaseCount('notification_delivery_attempts',0);
    }
    public function test_read_seen_and_action_done_are_distinct_and_source_is_reauthorized(): void
    {
        [$owner,$assignee,,,$project]=$this->fixture();
        $action=app(ProjectExecutionWriter::class)->createAction($owner,$project->fresh(),['title'=>'State work','done_condition'=>'Done','assigned_to'=>$assignee->id],$project->fresh()->plan_version);
        $notification=CompanyNotification::firstOrFail();$this->assertTrue(app(NotificationAuthorization::class)->allowed($notification->load('recipient')));
        $notification->update(['read_at_utc'=>now('UTC')]);$this->assertNull($notification->fresh()->source_seen_at_utc);$this->assertNull($notification->fresh()->action_done_at_utc);
        app(ProjectExecutionWriter::class)->transitionAction($assignee,$action->fresh(),'complete',$project->fresh()->plan_version);
        $this->assertNotNull($notification->fresh()->action_done_at_utc);$this->assertNull($notification->fresh()->source_seen_at_utc);
        $action->update(['assigned_to'=>$owner->id]);$this->assertFalse(app(NotificationAuthorization::class)->allowed($notification->fresh('recipient')));
    }
    public function test_push_endpoint_allowlist_blocks_ssrf_targets(): void
    {
        [$owner,,,$organization]=$this->fixture();$session=['access_mode'=>'workspace','current_company_id'=>$organization->id,'current_company_access_epoch'=>1,'credential_generation'=>1];
        $this->actingAs($owner)->withSession($session)->postJson(route('push-subscriptions.store'),['endpoint'=>'https://127.0.0.1/push','keys'=>['p256dh'=>'key','auth'=>'secret']])
            ->assertUnprocessable()->assertJsonValidationErrors('endpoint');
    }
    public function test_service_worker_has_zero_business_navigation_cache(): void
    {
        $worker=file_get_contents(public_path('service-worker.js'));
        $this->assertStringContainsString("event.request.mode === 'navigate'",$worker);
        $this->assertStringContainsString("clients.matchAll({ type: 'window', includeUncontrolled: true })",$worker);
        $this->assertStringContainsString('await existing.navigate(target)',$worker);
        $this->assertStringContainsString('requested.origin === self.location.origin',$worker);
        $this->assertStringNotContainsString('/company/projects',$worker);$this->assertStringNotContainsString('session',$worker);$this->assertStringNotContainsString('csrf',$worker);
    }
    private function fixture(): array
    {
        $owner=User::factory()->create(['email_verified_at'=>now()]);$assignee=User::factory()->create(['email_verified_at'=>now()]);$reviewer=User::factory()->create(['email_verified_at'=>now()]);
        $organization=Organization::create(['name'=>'Scope 10 Org','slug'=>'scope-10-'.uniqid()]);
        $workspace=Workspace::create(['organization_id'=>$organization->id,'owner_user_id'=>$owner->id,'name'=>'Notification','slug'=>'notification-'.uniqid(),'status'=>Workspace::STATUS_ACTIVE]);
        foreach([[$owner,'owner'],[$assignee,'member'],[$reviewer,'member']] as [$user,$role]){
            $organization->users()->attach($user->id,['role'=>$role,'organization_role'=>$role,'membership_status'=>'active','access_epoch'=>1,'joined_at'=>now()]);
            $workspace->users()->attach($user->id,['role'=>$role,'joined_at'=>now()]);
            ProductAccountEligibility::create(['user_id'=>$user->id,'mode'=>ProductAccountEligibility::MODE_SINGLE,'product_organization_id'=>$organization->id,'classification_version'=>'scope10-test','classified_at'=>now(),'evidence_ref'=>'scope10-fixture']);
        }
        $writer=app(ProjectExecutionWriter::class);$project=$writer->createProject($owner,$workspace,['name'=>'Notification Project','purpose'=>'Coordinate','expected_outcome'=>'Visible']);
        $writer->addMember($owner,$project->fresh(),$assignee,$workspace,['member'],$project->fresh()->plan_version);
        $writer->addMember($owner,$project->fresh(),$reviewer,$workspace,['member'],$project->fresh()->plan_version);
        return [$owner,$assignee,$reviewer,$organization,$project];
    }
}
