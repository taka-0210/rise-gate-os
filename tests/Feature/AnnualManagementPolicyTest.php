<?php

namespace Tests\Feature;

use App\Models\AnnualManagementPolicy;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationGroup;
use App\Models\OrganizationUser;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyAccess;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyLifecycle;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyPermissionManager;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyRelationService;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicySnapshot;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicySourceProvider;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyWriter;
use App\Services\AnnualManagementPolicy\ManagementPeriodResolver;
use App\Services\AnnualManagementPolicy\ManagementPeriodWriter;
use App\Services\Organization\OrganizationAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use LogicException;
use Tests\TestCase;

class AnnualManagementPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_period_resolves_jst_boundaries_allows_gaps_and_rejects_overlap(): void
    {
        $organization = $this->organization('periods');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $writer = app(ManagementPeriodWriter::class);
        $first = $writer->register($owner, $organization, '前期', '2025-04-01', '2026-03-31', (string) Str::uuid());
        $second = $writer->register($owner, $organization, '今期', '2026-04-02', '2027-03-31', (string) Str::uuid());
        $resolver = app(ManagementPeriodResolver::class);
        $this->assertSame($first->id, $resolver->current($organization, '2026-03-31')->id);
        $this->assertNull($resolver->current($organization, '2026-04-01'));
        $this->assertSame($second->id, $resolver->current($organization, '2026-04-02')->id);
        try {
            $writer->register($owner, $organization, '重複', '2027-03-31', '2027-04-30', (string) Str::uuid());
            $this->fail('Overlapping periods must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('period', $exception->errors());
        }
        $corrected = $writer->correct($owner, $organization, $second, '今期（訂正）', '2026-04-02', '2027-03-30', 1, 'Human correction', (string) Str::uuid());
        $this->assertSame(2, $corrected->version);
        $this->assertSame(['前期', '今期（訂正）'], $organization->managementPeriods()->orderBy('starts_on')->pluck('name')->all());
        $this->assertDatabaseCount('organization_management_period_versions', 3);
    }

    public function test_company_period_term_number_is_optional_versioned_unique_and_never_inferred(): void
    {
        $organization = $this->organization('term-number');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $writer = app(ManagementPeriodWriter::class);
        $legacy = $writer->register($owner, $organization, '2025年度', '2025-01-01', '2025-12-31', (string) Str::uuid());
        $current = $writer->register($owner, $organization, '2026年度', '2026-01-01', '2026-12-31', (string) Str::uuid(), 23);
        $this->assertNull($legacy->fiscal_term_number);
        $this->assertSame(23, $current->fiscal_term_number);
        $this->assertSame('第23期｜2026年度', $current->display_label);
        $corrected = $writer->correct(
            $owner, $organization, $current, '2026年度', '2026-01-01', '2026-12-31',
            1, '期数確認', (string) Str::uuid(), 24,
        );
        $this->assertSame(24, $corrected->fiscal_term_number);
        $this->assertSame([23, 24], $corrected->versions()->reorder('version_no')->pluck('fiscal_term_number')->all());
        try {
            $writer->register($owner, $organization, '2027年度', '2027-01-01', '2027-12-31', (string) Str::uuid(), 24);
            $this->fail('A fiscal term number must be unique inside one organization.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fiscal_term_number', $exception->errors());
        }
    }

    public function test_approval_and_effective_lifecycle_are_independent_and_jst_bound(): void
    {
        $organization = $this->organization('lifecycle');
        [$owner, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $policy = $this->policy($owner, $organization, '2026-12-01', '2027-11-30');
        $this->grants($owner, $policy, [$membership->id=>['can_view_approved'=>true,'can_view_draft'=>true,'can_edit'=>true,'can_approve'=>true]]);
        $lifecycle = app(AnnualManagementPolicyLifecycle::class);
        $this->assertSame([
            'approval_status'=>'draft', 'effective_status'=>'upcoming',
            'evaluated_on'=>'2026-10-03', 'timezone'=>'Asia/Tokyo',
        ], $lifecycle->evaluate($policy->period, false, '2026-10-03'));
        $writer = app(AnnualManagementPolicyWriter::class);
        $saved = $writer->saveDraft($owner, $policy, $this->draft([
            'starts_on'=>'2026-12-01', 'ends_on'=>'2027-11-30',
        ]), 0, (string) Str::uuid());
        $preview = $writer->preview($owner, $saved);
        $writer->approve($owner, $saved, 1, null, 0, $preview['snapshot_hash'], false, null, (string) Str::uuid());
        $this->assertSame('承認済み / 開始前', $lifecycle->label($lifecycle->evaluate($policy->period, true, '2026-10-03')));
        $this->assertSame('effective', $lifecycle->evaluate($policy->period, true, '2026-12-01')['effective_status']);
        $this->assertSame('effective', $lifecycle->evaluate($policy->period, true, '2027-11-30')['effective_status']);
        $this->assertSame('ended', $lifecycle->evaluate($policy->period, true, '2027-12-01')['effective_status']);
        $export = app(AnnualManagementPolicySourceProvider::class)->export(
            $owner, $organization, AnnualManagementPolicySourceProvider::MODE_PERIOD,
            ['annual_public_id'=>$policy->public_id, 'evaluated_on'=>'2026-10-03'],
        );
        $this->assertSame(2, $export['schema_version']);
        $this->assertSame('approved', $export['approval_status']);
        $this->assertSame('upcoming', $export['effective_status']);
        $this->assertSame('Asia/Tokyo', $export['currentness']['timezone']);
    }

    public function test_owner_manage_does_not_bypass_body_capabilities_and_grants_are_independent(): void
    {
        $organization = $this->organization('access');
        [$owner, $ownerMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$staff, $staffMembership] = $this->member($organization);
        $policy = $this->policy($owner, $organization);
        $access = app(AnnualManagementPolicyAccess::class);
        $this->assertTrue($access->canManage($owner, $organization));
        $this->assertFalse($access->canViewDraft($owner, $policy));
        $this->assertFalse($access->canEdit($owner, $policy));
        $this->grants($owner, $policy, [
            $ownerMembership->id => ['can_view_draft'=>true,'can_edit'=>true,'can_approve'=>true],
            $staffMembership->id => ['can_view_approved'=>true],
        ]);
        $this->assertTrue($access->canEdit($owner, $policy->fresh()));
        $this->assertTrue($access->canApprove($owner, $policy->fresh()));
        $this->assertTrue($access->canViewApproved($staff, $policy->fresh()));
        $this->assertFalse($access->canViewDraft($staff, $policy->fresh()));
        $staffMembership->update(['membership_status'=>OrganizationUser::STATUS_SUSPENDED]);
        $this->assertFalse($access->canViewApproved($staff, $policy->fresh()));
    }

    public function test_permission_configuration_rejects_edit_or_approve_without_draft_view(): void
    {
        $organization = $this->organization('grant-shape');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [, $membership] = $this->member($organization);
        $policy = $this->policy($owner, $organization);
        foreach (['can_edit','can_approve'] as $capability) {
            try {
                $this->grants($owner, $policy, [$membership->id => [$capability=>true]]);
                $this->fail('Capability without draft view must fail.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('grants', $exception->errors());
            }
        }
        $this->assertDatabaseCount('annual_management_policy_grants', 0);
    }

    public function test_all_active_staff_scope_only_shares_approved_content_and_audience_export_reauthorizes(): void
    {
        $organization = $this->organization('all-active');
        [$owner, $ownerMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$staff, $staffMembership] = $this->member($organization);
        $policy = $this->policy($owner, $organization);
        $this->grants($owner, $policy, [
            $ownerMembership->id => ['can_view_draft'=>true,'can_edit'=>true,'can_approve'=>true],
        ], AnnualManagementPolicy::VIEW_SCOPE_ALL_ACTIVE_STAFF);
        $access = app(AnnualManagementPolicyAccess::class);
        $this->assertTrue($access->canViewApproved($staff, $policy->fresh()));
        $this->assertFalse($access->canViewDraft($staff, $policy->fresh()));
        $writer = app(AnnualManagementPolicyWriter::class);
        $saved = $writer->saveDraft($owner, $policy->fresh(), $this->draft(), 0, (string) Str::uuid());
        $preview = $writer->preview($owner, $saved);
        $writer->approve($owner, $saved, 1, null, 0, $preview['snapshot_hash'], false, null, (string) Str::uuid());
        $export = app(AnnualManagementPolicySourceProvider::class)->exportForAudience(
            [$owner, $staff], $organization, AnnualManagementPolicySourceProvider::MODE_CURRENT,
            ['evaluated_on'=>'2026-10-03'],
        );
        $this->assertSame('approved', $export['approval_status']);
        $staffMembership->update(['membership_status'=>OrganizationUser::STATUS_SUSPENDED]);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(AnnualManagementPolicySourceProvider::class)->exportForAudience(
            [$owner, $staff], $organization, AnnualManagementPolicySourceProvider::MODE_CURRENT,
            ['evaluated_on'=>'2026-10-03'],
        );
    }

    public function test_draft_and_approval_requests_are_idempotent_and_reject_request_id_payload_reuse(): void
    {
        $organization = $this->organization('idempotency');
        [$owner, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $policy = $this->policy($owner, $organization);
        $this->grants($owner, $policy, [$membership->id=>['can_view_approved'=>true,'can_view_draft'=>true,'can_edit'=>true,'can_approve'=>true]]);
        $writer = app(AnnualManagementPolicyWriter::class);
        $saveRequest = (string) Str::uuid();
        $saved = $writer->saveDraft($owner, $policy, $this->draft(), 0, $saveRequest);
        $replayed = $writer->saveDraft($owner, $saved, $this->draft(), 0, $saveRequest);
        $this->assertSame(1, $replayed->draft_version);
        $this->assertDatabaseCount('annual_management_policy_operations', 4);
        try {
            $writer->saveDraft($owner, $replayed, $this->draft(['policy'=>'別内容']), 0, $saveRequest);
            $this->fail('A request id cannot be reused with another payload.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request_id', $exception->errors());
        }
        $preview = $writer->preview($owner, $replayed);
        $approveRequest = (string) Str::uuid();
        $revision = $writer->approve($owner, $replayed, 1, null, 0, $preview['snapshot_hash'], false, null, $approveRequest);
        $sameRevision = $writer->approve($owner, $replayed->fresh(), 1, null, 0, $preview['snapshot_hash'], false, null, $approveRequest);
        $this->assertSame($revision->id, $sameRevision->id);
        $this->assertDatabaseCount('annual_management_policy_revisions', 1);
    }

    public function test_draft_save_is_not_approval_and_approval_creates_immutable_full_snapshot(): void
    {
        $organization = $this->organization('approval');
        [$owner, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $group = OrganizationGroup::create(['organization_id'=>$organization->id,'name'=>'営業部']);
        $policy = $this->policy($owner, $organization);
        $this->grants($owner, $policy, [$membership->id=>['can_view_approved'=>true,'can_view_draft'=>true,'can_edit'=>true,'can_approve'=>true]]);
        $writer = app(AnnualManagementPolicyWriter::class);
        $saved = $writer->saveDraft($owner, $policy, $this->draft([
            'themes'=>[['statement'=>'信頼','explanation'=>'長期の信頼','priorities'=>[['statement'=>'品質を優先','explanation'=>null]]]],
            'departments'=>[['group_public_id'=>$group->public_id,'introduction'=>'営業の役割','statements'=>[['statement'=>'顧客理解を深める','explanation'=>'対話を増やす'],['statement'=>'継続関係を築く']]]],
        ]), 0, (string) Str::uuid());
        $this->assertSame(1, $saved->draft_version);
        $this->assertNull($saved->current_approved_revision_id);
        $preview = $writer->preview($owner, $saved);
        $revision = $writer->approve($owner, $saved, 1, null, 0, $preview['snapshot_hash'], false, '初回承認', (string) Str::uuid());
        $this->assertSame(1, $revision->revision_no);
        $this->assertSame('顧客価値を優先する', $revision->snapshot['annual']['policy']);
        $this->assertCount(2, $revision->snapshot['annual']['departments'][0]['statements']);
        $this->assertSame($revision->id, $saved->fresh()->current_approved_revision_id);
        $oldSnapshot = $revision->snapshot;
        $writer->saveDraft($owner, $saved->fresh(), $this->draft(['policy'=>'改定中の方針']), 1, (string) Str::uuid());
        $this->assertSame($oldSnapshot, $revision->fresh()->snapshot);
        $this->assertSame('顧客価値を優先する', $saved->fresh()->currentApprovedRevision->snapshot['annual']['policy']);
    }

    public function test_approval_validation_requires_valid_period_policy_and_department_statement(): void
    {
        $organization = $this->organization('approval-rules');
        [$owner, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $group = OrganizationGroup::create(['organization_id'=>$organization->id,'name'=>'開発部']);
        $policy = $this->policy($owner, $organization);
        $this->grants($owner, $policy, [$membership->id=>['can_view_draft'=>true,'can_edit'=>true,'can_approve'=>true]]);
        $writer = app(AnnualManagementPolicyWriter::class);
        $saved = $writer->saveDraft($owner, $policy, $this->draft(['policy'=>'','departments'=>[['group_public_id'=>$group->public_id,'statements'=>[]]]]), 0, (string) Str::uuid());
        $preview = $writer->preview($owner, $saved);
        try {
            $writer->approve($owner, $saved, 1, null, 0, $preview['snapshot_hash'], false, null, (string) Str::uuid());
            $this->fail('Invalid approval must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('policy', $exception->errors());
        }
        $this->assertDatabaseCount('annual_management_policy_revisions', 0);
        $saved = $writer->saveDraft($owner, $saved->fresh(), $this->draft(['departments'=>[['group_public_id'=>$group->public_id,'statements'=>[]]]]), 1, (string) Str::uuid());
        $preview = $writer->preview($owner, $saved);
        try {
            $writer->approve($owner, $saved, 2, null, 0, $preview['snapshot_hash'], false, null, (string) Str::uuid());
            $this->fail('Department without statement must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('departments.0', $exception->errors());
        }
    }

    public function test_stale_approval_and_fault_injection_leave_no_partial_revision(): void
    {
        $organization = $this->organization('approval-stale');
        [$owner, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $policy = $this->policy($owner, $organization);
        $this->grants($owner, $policy, [$membership->id=>['can_view_draft'=>true,'can_edit'=>true,'can_approve'=>true]]);
        $writer = app(AnnualManagementPolicyWriter::class);
        $saved = $writer->saveDraft($owner, $policy, $this->draft(), 0, (string) Str::uuid());
        $preview = $writer->preview($owner, $saved);
        $saved = $writer->saveDraft($owner, $saved, $this->draft(['policy'=>'更新後']), 1, (string) Str::uuid());
        $this->expectException(ValidationException::class);
        $writer->approve($owner, $saved, 1, null, 0, $preview['snapshot_hash'], false, null, (string) Str::uuid());
    }

    public function test_approval_preview_is_bound_to_current_sharing_and_permission_state(): void
    {
        $organization=$this->organization('approval-access-binding');
        [$owner,$ownerMembership]=$this->member($organization,OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [, $viewerMembership]=$this->member($organization);
        $policy=$this->policy($owner,$organization);
        $this->grants($owner,$policy,[$ownerMembership->id=>[
            'can_view_approved'=>true,'can_view_draft'=>true,'can_edit'=>true,'can_approve'=>true,
        ]]);
        $writer=app(AnnualManagementPolicyWriter::class);
        $saved=$writer->saveDraft($owner,$policy,$this->draft(),0,(string) Str::uuid());
        $preview=$writer->preview($owner,$saved);
        $this->assertSame(1,data_get($preview,'snapshot.annual.access_binding.approver_count'));
        $this->grants($owner,$saved->fresh(),[
            $ownerMembership->id=>['can_view_approved'=>true,'can_view_draft'=>true,'can_edit'=>true,'can_approve'=>true],
            $viewerMembership->id=>['can_view_approved'=>true],
        ]);
        try {
            $writer->approve($owner,$saved->fresh(),1,null,0,$preview['snapshot_hash'],false,null,(string) Str::uuid());
            $this->fail('Approval must stop when the reviewed sharing state changes.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('snapshot_hash',$exception->errors());
        }
        $this->assertDatabaseCount('annual_management_policy_revisions',0);
    }

    public function test_source_modes_unicode_ranges_citation_and_membership_reauthorization(): void
    {
        $organization = $this->organization('source');
        [$owner, $ownerMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$staff, $staffMembership] = $this->member($organization);
        $policy = $this->policy($owner, $organization);
        $this->grants($owner, $policy, [
            $ownerMembership->id=>['can_view_approved'=>true,'can_view_draft'=>true,'can_edit'=>true,'can_approve'=>true],
            $staffMembership->id=>['can_view_approved'=>true],
        ]);
        $writer = app(AnnualManagementPolicyWriter::class);
        $saved = $writer->saveDraft($owner, $policy, $this->draft(['policy'=>'顧客😀価値を優先する']), 0, (string) Str::uuid());
        $preview = $writer->preview($owner, $saved);
        $writer->approve($owner, $saved, 1, null, 0, $preview['snapshot_hash'], false, null, (string) Str::uuid());
        $provider = app(AnnualManagementPolicySourceProvider::class);
        $export = $provider->export($staff, $organization, AnnualManagementPolicySourceProvider::MODE_HISTORICAL, ['annual_public_id'=>$policy->public_id,'revision_no'=>1]);
        $unit = collect($export['units'])->firstWhere('element_kind','policy');
        $this->assertSame(mb_strlen('顧客😀価値を優先する'), $unit['range']['end']);
        $this->assertSame('顧客😀価値を優先する', $provider->resolveCitation($staff, $organization, $unit['citation_handle'])['text']);
        $this->assertTrue($export['currentness']['requires_ai_policy']);
        $staffMembership->update(['membership_status'=>OrganizationUser::STATUS_LEFT, 'access_epoch'=>2]);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $provider->resolveCitation($staff, $organization, $unit['citation_handle']);
    }

    public function test_period_citation_remains_bound_to_its_exact_revision_after_later_approval(): void
    {
        $organization=$this->organization('citation-revision');
        [$owner,$membership]=$this->member($organization,OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $policy=$this->policy($owner,$organization);
        $this->grants($owner,$policy,[$membership->id=>['can_view_approved'=>true,'can_view_draft'=>true,'can_edit'=>true,'can_approve'=>true]]);
        $writer=app(AnnualManagementPolicyWriter::class);
        $first=$writer->saveDraft($owner,$policy,$this->draft(['policy'=>'初版の方針']),0,(string) Str::uuid());
        $preview=$writer->preview($owner,$first);
        $writer->approve($owner,$first,1,null,0,$preview['snapshot_hash'],false,null,(string) Str::uuid());
        $provider=app(AnnualManagementPolicySourceProvider::class);
        $period=$provider->export($owner,$organization,AnnualManagementPolicySourceProvider::MODE_PERIOD,['period_public_id'=>$policy->period->public_id]);
        $handle=collect($period['units'])->firstWhere('element_kind','policy')['citation_handle'];
        $current=$provider->export($owner,$organization,AnnualManagementPolicySourceProvider::MODE_CURRENT,['evaluated_on'=>'2026-10-03']);
        $currentHandle=collect($current['units'])->firstWhere('element_kind','policy')['citation_handle'];
        $this->assertSame('2026-10-03',$current['currentness']['evaluated_on']);
        $second=$writer->saveDraft($owner,$first->fresh(),$this->draft(['policy'=>'第二版の方針']),1,(string) Str::uuid());
        $preview=$writer->preview($owner,$second);
        $writer->approve($owner,$second,2,1,0,$preview['snapshot_hash'],false,null,(string) Str::uuid());
        $this->assertSame('初版の方針',$provider->resolveCitation($owner,$organization,$handle)['text']);
        $this->assertSame('初版の方針',$provider->resolveCitation($owner,$organization,$currentHandle)['text']);
    }

    public function test_current_and_draft_source_modes_have_distinct_authorization_and_status(): void
    {
        $organization = $this->organization('source-modes');
        [$owner, $ownerMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$viewer, $viewerMembership] = $this->member($organization);
        $policy = $this->policy($owner, $organization, '2026-01-01', '2026-12-31');
        $this->grants($owner, $policy, [
            $ownerMembership->id=>['can_view_approved'=>true,'can_view_draft'=>true,'can_edit'=>true,'can_approve'=>true],
            $viewerMembership->id=>['can_view_approved'=>true],
        ]);
        $writer = app(AnnualManagementPolicyWriter::class);
        $saved = $writer->saveDraft($owner, $policy, $this->draft(['starts_on'=>'2026-01-01','ends_on'=>'2026-12-31']), 0, (string) Str::uuid());
        $draft = app(AnnualManagementPolicySourceProvider::class)->export($owner, $organization, AnnualManagementPolicySourceProvider::MODE_DRAFT, ['annual_public_id'=>$policy->public_id,'draft_version'=>1]);
        $this->assertSame('draft', $draft['approval_status']);
        try {
            app(AnnualManagementPolicySourceProvider::class)->export($viewer, $organization, AnnualManagementPolicySourceProvider::MODE_DRAFT, ['annual_public_id'=>$policy->public_id,'draft_version'=>1]);
            $this->fail('Approved viewer must not receive Draft.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertTrue(true);
        }
        $preview=$writer->preview($owner,$saved);
        $writer->approve($owner,$saved,1,null,0,$preview['snapshot_hash'],false,null,(string) Str::uuid());
        $current=app(AnnualManagementPolicySourceProvider::class)->export($viewer,$organization,AnnualManagementPolicySourceProvider::MODE_CURRENT,['evaluated_on'=>'2026-10-03']);
        $this->assertSame('approved',$current['approval_status']);
        $this->assertSame('Asia/Tokyo',$current['currentness']['timezone']);
    }

    public function test_ui_separates_read_edit_approval_history_and_is_responsive(): void
    {
        $organization=$this->organization('ui');
        [$owner,$membership]=$this->member($organization,OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $policy=$this->policy($owner,$organization);
        $this->grants($owner,$policy,[$membership->id=>['can_view_approved'=>true,'can_view_draft'=>true,'can_edit'=>true,'can_approve'=>true]]);
        $this->asCompany($owner,$organization)->get(route('company.home'))->assertOk()->assertSee('ANNUAL MANAGEMENT POLICY');
        $this->asCompany($owner,$organization)->get(route('annual-management-policy.index'))->assertOk()->assertSee('年度経営方針')->assertSee('作成中 / 未承認')->assertSee('grid-template-areas: "header" "breadcrumbs" "main"',false);
        $this->asCompany($owner,$organization)->get(route('annual-management-policy.show',$policy))->assertOk()->assertSee('正式Revisionはまだありません')->assertSee('作成・承認・管理')->assertDontSee('<textarea',false);
        $this->asCompany($owner,$organization)->get(route('annual-management-policy.edit',$policy))->assertOk()->assertSee('Draftを保存')->assertSee('data-add-theme',false)->assertSee('data-move-up',false)->assertSee('今期、何を実現したいのか')->assertSee('部署がまだ登録されていません')->assertSee('after_save',false)->assertSee('@media(max-width:640px)',false);
        $this->asCompany($owner,$organization)->get(route('annual-management-policy.permissions',$policy))
            ->assertOk()
            ->assertSee('在籍中のスタッフ全員へ共有')
            ->assertSee('選んだ人へ共有')
            ->assertSee('この設定は承認後、開始日前にも適用されます。')
            ->assertSee('期の開始によって共有範囲が自動で変わることはありません。');
    }

    public function test_empty_optional_collections_long_japanese_and_many_items_are_supported(): void
    {
        $organization=$this->organization('volume');
        [$owner,$membership]=$this->member($organization,OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $policy=$this->policy($owner,$organization);
        $this->grants($owner,$policy,[$membership->id=>['can_view_draft'=>true,'can_edit'=>true,'can_approve'=>true]]);
        $long=str_repeat('会社の判断を丁寧に言葉として残します。',1200);
        $themes=[];
        for($i=0;$i<40;$i++){$themes[]=['statement'=>'Theme '.$i,'priorities'=>array_map(fn($n)=>['statement'=>'Priority '.$i.'-'.$n],range(1,5))];}
        $started=microtime(true);
        $saved=app(AnnualManagementPolicyWriter::class)->saveDraft($owner,$policy,$this->draft(['purpose'=>null,'background'=>null,'policy'=>$long,'themes'=>$themes,'departments'=>[]]),0,(string) Str::uuid());
        $this->assertLessThan(5.0,microtime(true)-$started);
        $this->assertSame($long,$saved->draft_policy);
        $this->assertCount(40,$saved->themes);
        $this->assertCount(5,$saved->themes->first()->priorities);
        $this->assertCount(0,$saved->departments);
    }

    public function test_department_identity_is_stable_per_annual_and_group_and_duplicates_fail_closed(): void
    {
        $organization=$this->organization('department-identity');
        [$owner,$membership]=$this->member($organization,OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $group=OrganizationGroup::create(['organization_id'=>$organization->id,'name'=>'営業部']);
        $policy=$this->policy($owner,$organization);
        $this->grants($owner,$policy,[$membership->id=>['can_view_draft'=>true,'can_edit'=>true]]);
        $writer=app(AnnualManagementPolicyWriter::class);
        $first=$writer->saveDraft($owner,$policy,$this->draft(['departments'=>[[
            'group_public_id'=>$group->public_id,'statements'=>[['statement'=>'最初の方針']],
        ]]]),0,(string) Str::uuid());
        $department=$first->departments->first();
        $second=$writer->saveDraft($owner,$first,$this->draft(['departments'=>[[
            'group_public_id'=>$group->public_id,'statements'=>[['statement'=>'更新後の方針']],
        ]]]),1,(string) Str::uuid());
        $this->assertSame($department->public_id,$second->departments->first()->public_id);
        $this->assertDatabaseCount('annual_management_policy_departments',1);
        try {
            $writer->saveDraft($owner,$second,$this->draft(['departments'=>[
                ['group_public_id'=>$group->public_id,'statements'=>[['statement'=>'A']]],
                ['group_public_id'=>$group->public_id,'statements'=>[['statement'=>'B']]],
            ]]),2,(string) Str::uuid());
            $this->fail('One annual policy cannot contain duplicate Department entities for one Group.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('departments.1.group_public_id',$exception->errors());
        }
        $this->assertSame('更新後の方針',$second->fresh('departments.statements')->departments->first()->statements->first()->statement);
    }

    public function test_explicit_reordering_preserves_public_ids_and_snapshot_order(): void
    {
        $organization=$this->organization('ordering');
        [$owner,$membership]=$this->member($organization,OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $sales=OrganizationGroup::create(['organization_id'=>$organization->id,'name'=>'営業部']);
        $support=OrganizationGroup::create(['organization_id'=>$organization->id,'name'=>'支援部']);
        $policy=$this->policy($owner,$organization);
        $this->grants($owner,$policy,[$membership->id=>['can_view_approved'=>true,'can_view_draft'=>true,'can_edit'=>true,'can_approve'=>true]]);
        $writer=app(AnnualManagementPolicyWriter::class);
        $first=$writer->saveDraft($owner,$policy,$this->draft([
            'themes'=>[
                ['statement'=>'Theme A','priorities'=>[['statement'=>'A1'],['statement'=>'A2']]],
                ['statement'=>'Theme B','priorities'=>[['statement'=>'B1']]],
            ],
            'departments'=>[
                ['group_public_id'=>$sales->public_id,'statements'=>[['statement'=>'Sales 1'],['statement'=>'Sales 2']]],
                ['group_public_id'=>$support->public_id,'statements'=>[['statement'=>'Support 1']]],
            ],
        ]),0,(string)Str::uuid());
        $themes=$first->themes;
        $departments=$first->departments;
        $second=$writer->saveDraft($owner,$first,$this->draft([
            'themes'=>[
                ['public_id'=>$themes[1]->public_id,'statement'=>'Theme B','priorities'=>[['public_id'=>$themes[1]->priorities[0]->public_id,'statement'=>'B1']]],
                ['public_id'=>$themes[0]->public_id,'statement'=>'Theme A','priorities'=>[
                    ['public_id'=>$themes[0]->priorities[1]->public_id,'statement'=>'A2'],
                    ['public_id'=>$themes[0]->priorities[0]->public_id,'statement'=>'A1'],
                ]],
            ],
            'departments'=>[
                ['public_id'=>$departments[1]->public_id,'group_public_id'=>$support->public_id,'statements'=>[['public_id'=>$departments[1]->statements[0]->public_id,'statement'=>'Support 1']]],
                ['public_id'=>$departments[0]->public_id,'group_public_id'=>$sales->public_id,'statements'=>[
                    ['public_id'=>$departments[0]->statements[1]->public_id,'statement'=>'Sales 2'],
                    ['public_id'=>$departments[0]->statements[0]->public_id,'statement'=>'Sales 1'],
                ]],
            ],
        ]),1,(string)Str::uuid());
        $this->assertSame(['Theme B','Theme A'],$second->themes->pluck('statement')->all());
        $this->assertSame(['A2','A1'],$second->themes[1]->priorities->pluck('statement')->all());
        $this->assertSame(['支援部','営業部'],$second->departments->pluck('group.name')->all());
        $this->assertSame(['Sales 2','Sales 1'],$second->departments[1]->statements->pluck('statement')->all());
        $preview=$writer->preview($owner,$second);
        $this->assertSame(['Theme B','Theme A'],collect($preview['snapshot']['annual']['themes'])->pluck('statement')->all());
        $this->assertSame(['支援部','営業部'],collect($preview['snapshot']['annual']['departments'])->pluck('group_name_at_approval')->all());
    }

    public function test_department_setup_return_saves_draft_and_only_accepts_fixed_destination(): void
    {
        $organization=$this->organization('department-return');
        [$owner,$membership]=$this->member($organization,OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $policy=$this->policy($owner,$organization);
        $this->grants($owner,$policy,[$membership->id=>['can_view_draft'=>true,'can_edit'=>true]]);
        $payload=$this->draft([
            'request_id'=>(string)Str::uuid(),
            'expected_draft_version'=>0,
            'after_save'=>'organization_groups',
        ]);
        $this->asCompany($owner,$organization)
            ->put(route('annual-management-policy.update',$policy),$payload)
            ->assertRedirect(route('organization-management.index',[
                'return_to_annual_policy'=>$policy->public_id,
            ]));
        $this->assertSame(1,$policy->fresh()->draft_version);
        $payload['request_id']=(string)Str::uuid();
        $payload['expected_draft_version']=1;
        $payload['after_save']='https://example.test';
        $this->asCompany($owner,$organization)
            ->put(route('annual-management-policy.update',$policy),$payload)
            ->assertSessionHasErrors('after_save');
        $this->assertSame(1,$policy->fresh()->draft_version);
    }

    public function test_tenant_routes_and_source_provider_do_not_cross_organization_boundary(): void
    {
        $first=$this->organization('tenant-a');[$owner,$membership]=$this->member($first,OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $policy=$this->policy($owner,$first);$this->grants($owner,$policy,[$membership->id=>['can_view_draft'=>true]]);
        $second=$this->organization('tenant-b');[$other]=$this->member($second,OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->asCompany($other,$second)->get(route('annual-management-policy.show',$policy))->assertNotFound();
        try {
            app(AnnualManagementPolicySourceProvider::class)->export($other,$second,AnnualManagementPolicySourceProvider::MODE_DRAFT,['annual_public_id'=>$policy->public_id,'draft_version'=>0]);
            $this->fail('Cross-tenant source lookup must fail.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    public function test_manual_relations_are_typed_versioned_and_post_approval_changes_do_not_create_body_revision(): void
    {
        $organization=$this->organization('relations');
        [$owner,$membership]=$this->member($organization,OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $group=OrganizationGroup::create(['organization_id'=>$organization->id,'name'=>'事業部']);
        $policy=$this->policy($owner,$organization);
        $this->grants($owner,$policy,[$membership->id=>['can_view_approved'=>true,'can_view_draft'=>true,'can_edit'=>true,'can_approve'=>true]]);
        $writer=app(AnnualManagementPolicyWriter::class);
        $saved=$writer->saveDraft($owner,$policy,$this->draft(['themes'=>[['statement'=>'成長','priorities'=>[['statement'=>'重点市場']]]]]),0,(string) Str::uuid());
        $preview=$writer->preview($owner,$saved);
        $writer->approve($owner,$saved,1,null,0,$preview['snapshot_hash'],false,null,(string) Str::uuid());
        $priority=$saved->fresh('themes.priorities')->themes->first()->priorities->first();
        $relations=app(AnnualManagementPolicyRelationService::class);
        $relation=$relations->confirm($owner,$saved->fresh(),'priority',$priority->public_id,'group',$group->public_id,0,'Human confirmed',(string) Str::uuid());
        $this->assertSame(1,$saved->fresh()->relation_version);
        $this->assertSame(1,$relation->current_version);
        $this->assertDatabaseCount('annual_management_policy_revisions',1);
        $relations->withdraw($owner,$saved->fresh(),$relation,1,'No longer applies',(string) Str::uuid());
        $this->assertSame(2,$saved->fresh()->relation_version);
        $this->assertSame(2,$relation->fresh()->current_version);
        $this->assertDatabaseCount('annual_management_policy_relation_versions',2);
        $this->assertDatabaseCount('annual_management_policy_revisions',1);
        try {
            $relations->confirm($owner,$saved->fresh(),'annual',$saved->public_id,'group',$group->public_id,2,null,(string) Str::uuid());
            $this->fail('Annual to Group is outside the typed allowlist.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('relation',$exception->errors());
        }
    }

    public function test_project_and_action_relations_delegate_to_existing_reader_contracts(): void
    {
        $organization=$this->organization('execution-relations');
        [$owner,$membership]=$this->member($organization,OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $workspace=Workspace::create([
            'organization_id'=>$organization->id,'owner_user_id'=>$owner->id,
            'name'=>'Execution Workspace','slug'=>'execution-'.strtolower((string) Str::ulid()),
        ]);
        $workspace->users()->attach($owner->id,['role'=>'owner','joined_at'=>now()]);
        $project=Project::create([
            'organization_id'=>$organization->id,'owning_workspace_id'=>$workspace->id,
            'billing_workspace_id'=>$workspace->id,'owner_user_id'=>$owner->id,'name'=>'Strategy execution',
        ]);
        ProjectMember::create([
            'project_id'=>$project->id,'user_id'=>$owner->id,'workspace_id'=>$workspace->id,
            'project_role'=>ProjectMember::ROLE_OWNER,'permission_level'=>ProjectMember::PERMISSION_ADMIN,
            'invited_by'=>$owner->id,'invited_at'=>now(),'accepted_at'=>now(),'status'=>ProjectMember::STATUS_ACTIVE,
        ]);
        $action=Task::create([
            'organization_id'=>$organization->id,'workspace_id'=>$workspace->id,
            'project_id'=>$project->id,'title'=>'Execute annual priority',
        ]);
        $policy=$this->policy($owner,$organization);
        $this->grants($owner,$policy,[$membership->id=>['can_view_draft'=>true,'can_edit'=>true]]);
        $relations=app(AnnualManagementPolicyRelationService::class);
        $projectRelation=$relations->confirm(
            $owner,$policy,'annual',$policy->public_id,'project',$project->public_id,0,null,(string) Str::uuid(),
        );
        $actionRelation=$relations->confirm(
            $owner,$policy->fresh(),'annual',$policy->public_id,'action',$action->public_id,1,null,(string) Str::uuid(),
        );
        $this->assertSame('project',$projectRelation->target_type);
        $this->assertSame('action',$actionRelation->target_type);
        $project->members()->where('user_id',$owner->id)->update(['status'=>ProjectMember::STATUS_LEFT]);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $relations->readableTarget($owner,$organization,'action',$action->public_id);
    }

    public function test_approval_transaction_rolls_back_revision_pointer_operation_and_audit_on_fault(): void
    {
        $organization=$this->organization('rollback');
        [$owner,$membership]=$this->member($organization,OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $policy=$this->policy($owner,$organization);
        $this->grants($owner,$policy,[$membership->id=>['can_view_draft'=>true,'can_edit'=>true,'can_approve'=>true]]);
        $normal=app(AnnualManagementPolicyWriter::class);
        $saved=$normal->saveDraft($owner,$policy,$this->draft(),0,(string) Str::uuid());
        $preview=$normal->preview($owner,$saved);
        $auditCount=OrganizationAuditEvent::count();
        $faulting=new class(app(AnnualManagementPolicyAccess::class),app(AnnualManagementPolicySnapshot::class),app(OrganizationAudit::class)) extends AnnualManagementPolicyWriter {
            protected function checkpoint(string $name): void { if($name==='after_revision'){throw new RuntimeException('synthetic rollback');} }
        };
        try {
            $faulting->approve($owner,$saved,1,null,0,$preview['snapshot_hash'],false,null,(string) Str::uuid());
            $this->fail('Fault must escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic rollback',$exception->getMessage());
        }
        $this->assertNull($saved->fresh()->current_approved_revision_id);
        $this->assertDatabaseCount('annual_management_policy_revisions',0);
        $this->assertSame($auditCount,OrganizationAuditEvent::count());
    }

    public function test_revisions_are_immutable_and_audit_does_not_copy_body_content(): void
    {
        $organization=$this->organization('immutability');
        [$owner,$membership]=$this->member($organization,OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $policy=$this->policy($owner,$organization);
        $this->grants($owner,$policy,[$membership->id=>['can_view_draft'=>true,'can_edit'=>true,'can_approve'=>true]]);
        $writer=app(AnnualManagementPolicyWriter::class);
        $saved=$writer->saveDraft($owner,$policy,$this->draft(['policy'=>'監査へ複製してはいけない本文']),0,(string) Str::uuid());
        $preview=$writer->preview($owner,$saved);
        $revision=$writer->approve($owner,$saved,1,null,0,$preview['snapshot_hash'],false,'理由も本文ではない',(string) Str::uuid());
        $audit=OrganizationAuditEvent::query()->where('organization_id',$organization->id)->get()->toJson(JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('監査へ複製してはいけない本文',$audit);
        try {
            $revision->update(['change_reason'=>'rewrite']);
            $this->fail('Revision update must fail.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
    }

    private function organization(string $label): Organization
    {
        return Organization::create(['name' => 'Org '.$label, 'slug' => $label.'-'.strtolower((string) Str::ulid())]);
    }

    private function member(Organization $organization, string $role = OrganizationUser::ORGANIZATION_ROLE_MEMBER): array
    {
        $user = User::factory()->create();
        $membership = OrganizationUser::create([
            'organization_id' => $organization->id, 'user_id' => $user->id,
            'role' => $role === OrganizationUser::ORGANIZATION_ROLE_OWNER ? OrganizationUser::ROLE_OWNER : OrganizationUser::ROLE_MEMBER,
            'organization_role' => $role, 'membership_status' => OrganizationUser::STATUS_ACTIVE,
            'access_epoch' => 1, 'permissions' => [], 'joined_at' => now(),
        ]);
        return [$user, $membership];
    }

    private function policy(User $owner, Organization $organization, string $start = '2026-04-01', string $end = '2027-03-31'): AnnualManagementPolicy
    {
        $period = app(ManagementPeriodWriter::class)->register($owner, $organization, '2026年度', $start, $end, (string) Str::uuid());
        return app(AnnualManagementPolicyPermissionManager::class)->initialize($owner, $organization, $period, (string) Str::uuid());
    }

    private function grants(User $owner, AnnualManagementPolicy $policy, array $grants, string $scope = AnnualManagementPolicy::VIEW_SCOPE_EXPLICIT): AnnualManagementPolicy
    {
        return app(AnnualManagementPolicyPermissionManager::class)->update($owner, $policy->organization, $policy, $scope, $grants, (string) Str::uuid());
    }

    private function draft(array $override = []): array
    {
        return array_replace_recursive([
            'period_name' => '2026年度', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'purpose' => '価値を届ける目的', 'background' => '現在の背景', 'policy' => '顧客価値を優先する',
            'themes' => [], 'departments' => [],
        ], $override);
    }

    private function asCompany(User $user, Organization $organization): static
    {
        return $this->actingAs($user)->withSession([
            'access_mode' => 'workspace', 'current_company_id' => $organization->id,
            'current_company_access_epoch' => 1, 'credential_generation' => 1,
        ]);
    }
}
