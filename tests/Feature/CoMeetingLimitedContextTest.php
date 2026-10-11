<?php

namespace Tests\Feature;

use App\Contracts\AiCommonProvider;
use App\Models\AiCommonConversation;
use App\Models\Organization;
use App\Models\OrganizationGroup;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Services\AiCommon\AiCommonManagementContext;
use App\Services\AiCommon\AiCommonPolicyWriter;
use App\Services\AiCommon\AiCommonResourcePolicyWriter;
use App\Services\AiCommon\AiCommonSharedContext;
use App\Services\AiCommon\AiCommonSharedConversationWriter;
use App\Services\AiCommon\AiCommonSharedCoWriter;
use App\Services\AiCommon\AiCommonSourceManifest;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyPermissionManager;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyWriter;
use App\Services\AnnualManagementPolicy\ManagementPeriodWriter;
use App\Services\ManagementDesign\ManagementDesignPermissionManager;
use App\Services\ManagementDesign\ManagementDesignWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CoMeetingLimitedContextTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $org = Organization::create(['name' => 'Synthetic meeting', 'slug' => 'meeting-'.Str::ulid()]);
        $user = User::factory()->create();
        $membership = OrganizationUser::create(['organization_id' => $org->id, 'user_id' => $user->id,
            'role' => 'owner', 'organization_role' => 'owner', 'membership_status' => 'active', 'access_epoch' => 1, 'joined_at' => now()]);
        ProductAccountEligibility::create(['user_id' => $user->id, 'mode' => 'single', 'product_organization_id' => $org->id,
            'classification_version' => 'meeting-test', 'classified_at' => now(), 'evidence_ref' => 'synthetic']);
        app(AiCommonPolicyWriter::class)->update($user, $org, ['is_enabled' => true,
            'allowed_categories' => ['common_entry', 'company_management']], null);
        app(ManagementDesignPermissionManager::class)->update($user, $org, 'philosophy', 'explicit',
            [$membership->id => ['can_view' => true, 'can_edit' => true]], (string) Str::uuid());
        $item = app(ManagementDesignWriter::class)->saveOfficial($user, $org, 'philosophy',
            ['statement' => '人を大切にする正式理念', 'sections' => []], 0, null, (string) Str::uuid());
        $conversation = app(AiCommonSharedConversationWriter::class)->create($user, $org,
            ['name' => '営業部の会議', 'purpose' => '年度方針を踏まえて論点・未決事項を整理する', 'operation_id' => (string) Str::uuid()]);

        return [$user, $org, $membership, $item, $conversation];
    }

    private function allow($user, $org, $type, $id): void
    {
        app(AiCommonResourcePolicyWriter::class)->update($user, $org, $type, $id, true);
    }

    public function test_voice_reply_reuses_shared_access_and_returns_no_body(): void
    {
        [$user, $org, $membership, , $room] = $this->fixture();
        $message = $room->messages()->create(['role' => 'assistant', 'content' => 'Synthetic reply',
            'visibility_status' => 'visible', 'source_lineage_version' => 'source-lineage.v1']);
        $url = route('ai-common.voice-reply.access', [$room, $message->public_id]);
        $this->actingAs($user)->withSession(['current_company_id' => $org->id])->getJson($url)
            ->assertOk()->assertExactJson(['allowed' => true, 'content_hash' => hash('sha256', 'Synthetic reply')])
            ->assertHeader('Cache-Control', 'no-store, private');
        $membership->update(['membership_status' => 'left']);
        $response = $this->getJson($url);
        $this->assertNotSame(200, $response->status());
        $response->assertDontSee('Synthetic reply');
    }

    public function test_voice_reply_rejects_human_message_and_wrong_conversation(): void
    {
        [$user, $org, , , $room] = $this->fixture();
        $message = $room->messages()->create(['role' => 'user', 'content' => 'Not an AI reply', 'visibility_status' => 'visible']);
        $this->actingAs($user)->withSession(['current_company_id' => $org->id])
            ->getJson(route('ai-common.voice-reply.access', [$room, $message->public_id]))->assertForbidden();
        $this->getJson(route('ai-common.voice-reply.access', [$room, (string) Str::ulid()]))->assertForbidden();
    }

    public function test_voice_reply_stops_after_source_consent_revocation(): void
    {
        [$user, $org, , $item, $room] = $this->fixture();
        $this->allow($user, $org, 'management_design', $item->public_id);
        $source = app(AiCommonSharedContext::class)->select($user, $org, $room, 'management_design', $item->public_id, 'Voice test');
        $message = $room->messages()->create(['role' => 'assistant', 'content' => 'Restricted reply',
            'visibility_status' => 'visible', 'source_lineage_version' => 'source-lineage.v1']);
        $message->sourceRevisions()->attach($source->current_revision_id);
        $url = route('ai-common.voice-reply.access', [$room, $message->public_id]);
        $this->actingAs($user)->withSession(['current_company_id' => $org->id])->getJson($url)->assertOk();
        app(AiCommonResourcePolicyWriter::class)->update($user, $org, 'management_design', $item->public_id, false);
        $this->getJson($url)->assertForbidden()->assertDontSee('Restricted reply');
    }

    public function test_voice_reply_supports_private_history_without_owner_bypass(): void
    {
        [$user, $org] = $this->fixture();
        $room = AiCommonConversation::create(['organization_id' => $org->id, 'user_id' => $user->id,
            'conversation_kind' => 'private', 'title' => 'Private fixture', 'status' => 'active']);
        $message = $room->messages()->create(['role' => 'assistant', 'content' => 'Private reply',
            'visibility_status' => 'visible', 'source_lineage_version' => 'source-lineage.v1']);
        $url = route('ai-common.voice-reply.access', [$room, $message->public_id]);
        $this->actingAs($user)->withSession(['current_company_id' => $org->id])->getJson($url)->assertOk();
        $room->update(['user_id' => User::factory()->create()->id]);
        $this->getJson($url)->assertForbidden()->assertDontSee('Private reply');
    }

    public function test_document_consent_is_default_off_even_for_owner(): void
    {
        [$user,$org,,$item,$room] = $this->fixture();
        $this->assertDatabaseCount('ai_resource_policies', 0);
        $this->expectException(AuthorizationException::class);
        app(AiCommonSharedContext::class)->select($user, $org, $room, 'management_design', $item->public_id, '会議');
    }

    public function test_existing_categories_never_inherit_management_consent(): void
    {
        [$user,$org,,$item,$room] = $this->fixture();
        $this->allow($user, $org, 'management_design', $item->public_id);
        app(AiCommonPolicyWriter::class)->update($user, $org, ['is_enabled' => true,
            'allowed_categories' => ['common_entry', 'project', 'business_domain']], 1);
        $this->expectException(AuthorizationException::class);
        app(AiCommonSharedContext::class)->select($user, $org, $room, 'management_design', $item->public_id, '会議');
    }

    public function test_consent_revoked_during_provider_io_discards_response(): void
    {
        [$user,$org,,$item,$room] = $this->fixture();
        $this->allow($user, $org, 'management_design', $item->public_id);
        $source = app(AiCommonSharedContext::class)->select($user, $org, $room, 'management_design', $item->public_id, '会議');
        $fake = new MeetingContextFakeProvider;
        $fake->onRespond = fn () => app(AiCommonResourcePolicyWriter::class)->update($user, $org, 'management_design', $item->public_id, false);
        $this->app->instance(AiCommonProvider::class, $fake);
        try {
            app(AiCommonSharedCoWriter::class)->request($user, $org, $room, ['operation_id' => (string) Str::uuid(),
                'content' => '整理して', 'source_ids' => [$source->id]]);
            $this->fail('Revoked response was published');
        } catch (AuthorizationException|ValidationException $error) {
            $this->assertSame(0, $room->messages()->where('role', 'assistant')->count());
            $this->assertSame(1, $fake->calls);
        }
    }

    public function test_every_shared_participant_requires_view_and_no_department_name_bypass(): void
    {
        [$user,$org,,$item,$room] = $this->fixture();
        $this->allow($user, $org, 'management_design', $item->public_id);
        $staff = User::factory()->create();
        OrganizationUser::create(['organization_id' => $org->id, 'user_id' => $staff->id, 'role' => 'member',
            'organization_role' => 'member', 'membership_status' => 'active', 'access_epoch' => 1, 'joined_at' => now()]);
        ProductAccountEligibility::create(['user_id' => $staff->id, 'mode' => 'single', 'product_organization_id' => $org->id,
            'classification_version' => 'meeting-test', 'classified_at' => now(), 'evidence_ref' => 'synthetic']);
        $writer = app(AiCommonSharedConversationWriter::class);
        $invitation = $writer->invite($user, $org, $room, $staff, ['operation_id' => (string) Str::uuid()]);
        $writer->accept($staff, $org, $invitation);
        $this->expectException(AuthorizationException::class);
        app(AiCommonSharedContext::class)->select($user, $org, $room, 'management_design', $item->public_id, '部署会議');
    }

    public function test_cross_organization_source_is_not_resolved_or_listed(): void
    {
        [$user,$org,,$item] = $this->fixture();
        $other = Organization::create(['name' => 'Other', 'slug' => 'other-'.Str::ulid()]);
        $this->assertSame([], app(AiCommonManagementContext::class)->catalogue($user, $other));
        $this->expectException(ModelNotFoundException::class);
        app(AiCommonManagementContext::class)->resolve($user, $other, 'management_design', $item->public_id);
    }

    public function test_owner_without_view_cannot_enable_document_consent(): void
    {
        [$user,$org,,$item] = $this->fixture();
        app(ManagementDesignPermissionManager::class)->update($user, $org, 'philosophy', 'explicit', [], (string) Str::uuid());
        $this->assertSame([], app(AiCommonManagementContext::class)->catalogue($user, $org));
        $this->expectException(AuthorizationException::class);
        $this->allow($user, $org, 'management_design', $item->public_id);
    }

    public function test_private_conversation_uses_same_management_consent_resolver(): void
    {
        [$user,$org,,$item] = $this->fixture();
        $this->allow($user, $org, 'management_design', $item->public_id);
        $room = AiCommonConversation::create(['organization_id' => $org->id, 'user_id' => $user->id, 'title' => 'Private', 'status' => 'active', 'version' => 1]);
        $source = app(AiCommonSourceManifest::class)->select($user, $org, $room, 'management_design', $item->public_id, '会議');
        $this->assertSame('人を大切にする正式理念', app(AiCommonSourceManifest::class)->authorizeRevision($user, $org, $source->currentRevision)['data']['statement']);
    }

    public function test_oversized_management_context_is_not_silently_truncated(): void
    {
        [$user,$org,,$item,$room] = $this->fixture();
        $this->allow($user, $org, 'management_design', $item->public_id);
        app(ManagementDesignWriter::class)->saveOfficial($user, $org, 'philosophy',
            ['statement' => str_repeat('正式本文', 6000), 'sections' => []], 1, '長い本文', (string) Str::uuid());
        $this->expectException(ValidationException::class);
        app(AiCommonSharedContext::class)->select($user, $org, $room, 'management_design', $item->public_id, '会議');
    }

    public function test_policy_and_meeting_forms_render_without_raw_id_entry(): void
    {
        [$user,$org,,$item,$room] = $this->fixture();
        $this->allow($user, $org, 'management_design', $item->public_id);
        $this->actingAs($user)->withSession($this->productOrganizationSession($user, $org) + ['access_mode' => 'workspace', 'credential_generation' => 1]);
        $this->post(route('ai-common.shared.sources.store', $room), ['resource_type' => 'management_design',
            'resource_public_id' => $item->public_id, 'selection_reason' => '会議'])->assertRedirect();
        $fake = new MeetingContextFakeProvider;
        $this->app->instance(AiCommonProvider::class, $fake);
        $this->post(route('ai-common.shared.co-requests.store', $room), ['operation_id' => (string) Str::uuid(),
            'content' => '論点を整理してください', 'source_ids' => [$room->sources()->firstOrFail()->id]])->assertRedirect();
        $this->assertSame(1, $fake->calls);
        $policy = $this->get(route('ai-common.policy.edit'))->assertOk()->assertSee('会社の経営指針')->assertSee('文書許可を保存');
        $meeting = $this->get(route('ai-common.shared.show', $room))->assertOk()->assertSee('会議で参照する経営指針')
            ->assertSee('COに論点整理を相談する')->assertSee('出典を確認')->assertSee('正式版の出典');
        if ($output = getenv('CO_MEETING_BROWSER_OUTPUT')) {
            $directory = realpath($output);
            $temporary = realpath(sys_get_temp_dir());
            $this->assertSame($temporary, realpath(dirname($directory)));
            $this->assertStringStartsWith('company-os-co-meeting-', basename($directory));
            file_put_contents($directory.'/policy.html', $policy->getContent());
            file_put_contents($directory.'/meeting.html', $meeting->getContent());
        }
    }

    public function test_vision_and_company_policy_use_official_revisions_only(): void
    {
        [$user,$org,$membership,,$room] = $this->fixture();
        foreach (['vision', 'policy'] as $type) {
            app(ManagementDesignPermissionManager::class)->update($user, $org, $type, 'explicit',
                [$membership->id => ['can_view' => true, 'can_edit' => true]], (string) Str::uuid());
            $item = app(ManagementDesignWriter::class)->saveOfficial($user, $org, $type,
                ['statement' => $type.'正式文', 'sections' => []], 0, null, (string) Str::uuid());
            $this->allow($user, $org, 'management_design', $item->public_id);
            $source = app(AiCommonSharedContext::class)->select($user, $org, $room, 'management_design', $item->public_id, '会議');
            $this->assertSame($type.'正式文', $source->projection['statement']);
            $this->assertSame(1, $source->projection['revision_no']);
        }
    }

    public function test_unapproved_annual_is_not_listed_and_cannot_receive_ai_consent(): void
    {
        [$user,$org,$membership] = $this->fixture();
        $period = app(ManagementPeriodWriter::class)->register($user, $org, '次期', '2026-12-01', '2027-11-30', (string) Str::uuid());
        $annual = app(AnnualManagementPolicyPermissionManager::class)->initialize($user, $org, $period, (string) Str::uuid());
        $this->assertCount(1, app(AiCommonManagementContext::class)->catalogue($user, $org));
        try {
            $this->allow($user, $org, 'annual_management_policy', $annual->public_id);
            $this->fail('Unapproved annual received AI consent');
        } catch (AuthorizationException|ValidationException $error) {
            $this->assertDatabaseMissing('ai_resource_policies', ['resource_public_id' => $annual->public_id]);
        }
    }

    public function test_formal_revision_is_sent_on_late_meeting_turn_and_no_action_is_created(): void
    {
        [$user,$org,,$item,$room] = $this->fixture();
        $this->allow($user, $org, 'management_design', $item->public_id);
        $source = app(AiCommonSharedContext::class)->select($user, $org, $room, 'management_design', $item->public_id, '会議');
        for ($i = 0; $i < 25; $i++) {
            $room->messages()->create(['role' => 'user', 'content' => '議論 '.$i]);
        }
        $fake = new MeetingContextFakeProvider;
        $this->app->instance(AiCommonProvider::class, $fake);
        app(AiCommonSharedCoWriter::class)->request($user, $org, $room, ['operation_id' => (string) Str::uuid(),
            'content' => '決定：担当者は田中。未決：期限。論点を整理して。', 'source_ids' => [$source->id]]);
        $this->assertSame('人を大切にする正式理念', $fake->sources[0]['data']['statement']);
        $this->assertSame(1, $fake->sources[0]['data']['revision_no']);
        $this->assertStringContainsString('未決事項', json_encode($fake->messages, JSON_UNESCAPED_UNICODE));
        $this->assertDatabaseCount('tasks', 0);
        $this->assertSame(1, $fake->calls);
    }

    public function test_permission_revocation_blocks_history_source_reuse(): void
    {
        [$user,$org,$membership,$item,$room] = $this->fixture();
        $this->allow($user, $org, 'management_design', $item->public_id);
        $source = app(AiCommonSharedContext::class)->select($user, $org, $room, 'management_design', $item->public_id, '会議');
        app(ManagementDesignPermissionManager::class)->update($user, $org, 'philosophy', 'explicit', [], (string) Str::uuid());
        $this->expectException(AuthorizationException::class);
        app(AiCommonSharedContext::class)->authorizeRevision($user, $org, $source->currentRevision);
    }

    public function test_revision_change_requires_reselection(): void
    {
        [$user,$org,,$item,$room] = $this->fixture();
        $this->allow($user, $org, 'management_design', $item->public_id);
        $source = app(AiCommonSharedContext::class)->select($user, $org, $room, 'management_design', $item->public_id, '会議');
        app(ManagementDesignWriter::class)->saveOfficial($user, $org, 'philosophy',
            ['statement' => '変更後の理念', 'sections' => []], 1, '改定', (string) Str::uuid());
        $this->expectException(ValidationException::class);
        app(AiCommonSharedContext::class)->authorizeRevision($user, $org, $source->currentRevision);
    }

    public function test_approved_planning_uses_approved_snapshot_not_new_draft(): void
    {
        [$user,$org,$membership,,$room] = $this->fixture();
        $period = app(ManagementPeriodWriter::class)->register($user, $org, '2026年度', '2026-12-01', '2027-11-30', (string) Str::uuid(), 23);
        $manager = app(AnnualManagementPolicyPermissionManager::class);
        $annual = $manager->initialize($user, $org, $period, (string) Str::uuid());
        $annual = $manager->update($user, $org, $annual, 'explicit', [$membership->id => ['can_view_approved' => true, 'can_view_draft' => true, 'can_edit' => true, 'can_approve' => true]], (string) Str::uuid());
        $writer = app(AnnualManagementPolicyWriter::class);
        $group = OrganizationGroup::create(['organization_id' => $org->id, 'name' => '営業部']);
        $draft = ['period_name' => '2026年度', 'starts_on' => '2026-12-01', 'ends_on' => '2027-11-30',
            'purpose' => '目的', 'background' => '背景', 'policy' => '承認済み方針', 'themes' => [],
            'departments' => [['group_public_id' => $group->public_id, 'introduction' => '営業の役割', 'statements' => [['statement' => '顧客理解を深める']]]]];
        $saved = $writer->saveDraft($user, $annual, $draft, 0, (string) Str::uuid());
        $preview = $writer->preview($user, $saved);
        $writer->approve($user, $saved, 1, null, 0, $preview['snapshot_hash'], false, null, (string) Str::uuid());
        $draft['policy'] = '未承認の変更';
        $writer->saveDraft($user, $annual->fresh(), $draft, 1, (string) Str::uuid());
        $this->allow($user, $org, 'annual_management_policy', $annual->public_id);
        $source = app(AiCommonSharedContext::class)->select($user, $org, $room, 'annual_management_policy', $annual->public_id, '次期会議');
        $this->assertSame('承認済み方針', $source->projection['content']['policy']);
        $this->assertSame('approved', $source->projection['approval_status']);
        $this->assertSame('upcoming', $source->projection['effective_status']);
        $this->assertSame('Asia/Tokyo', $source->projection['timezone']);
        $departmentId = $annual->fresh()->currentApprovedRevision->snapshot['annual']['departments'][0]['public_id'];
        $department = app(AiCommonSharedContext::class)->select($user, $org, $room, 'department_policy', $annual->public_id.':'.$departmentId, '営業部会議');
        $this->assertSame('営業部', $department->projection['content']['group_name_at_approval']);
        $this->assertSame('顧客理解を深める', $department->projection['content']['statements'][0]['statement']);
        $this->assertDatabaseMissing('ai_resource_policies', ['resource_type' => 'department_policy']);
        $manager->update($user, $org, $annual->fresh(), 'explicit', [], (string) Str::uuid());
        $this->expectException(AuthorizationException::class);
        app(AiCommonSharedContext::class)->authorizeRevision($user, $org, $source->currentRevision);
    }
}

class MeetingContextFakeProvider implements AiCommonProvider
{
    public array $messages = [];

    public array $sources = [];

    public int $calls = 0;

    public $onRespond = null;

    public function respond(array $messages, array $sources): array
    {
        $this->calls++;
        $this->messages = $messages;
        $this->sources = $sources;
        if ($this->onRespond) {
            ($this->onRespond)();
        }

        return ['answer' => '論点：期限。決定：担当者。未決：期限。最終判断は人間。',
            'citations' => array_column($sources, 'handle'), 'provider' => 'synthetic', 'model' => 'fake',
            'input_tokens' => 1, 'output_tokens' => 1];
    }
}
