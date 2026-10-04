<?php

namespace Tests\Feature;

use App\Models\AnnualManagementPolicy;
use App\Models\ManagementDesignItem;
use App\Models\Organization;
use App\Models\OrganizationGroup;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyPermissionManager;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyWriter;
use App\Services\AnnualManagementPolicy\ManagementPeriodWriter;
use App\Services\CompanyContextReader\CompanyContextReaderComposer;
use App\Services\ManagementDesign\ManagementDesignPermissionManager;
use App\Services\ManagementDesign\ManagementDesignWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompanyContextReaderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reader_uses_immutable_mdc_revision_and_omits_unauthorized_chapters_without_owner_bypass(): void
    {
        $organization = $this->organization('immutable');
        [$owner, $ownerMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$viewer, $viewerMembership] = $this->member($organization);
        $this->mdPermissions($owner, $organization, 'philosophy', [
            $viewerMembership->id => ['can_view' => true, 'can_edit' => true],
        ]);
        $this->mdPermissions($owner, $organization, 'vision', [
            $ownerMembership->id => ['can_view' => true, 'can_edit' => true],
        ]);
        $item = app(ManagementDesignWriter::class)->saveOfficial($viewer, $organization, 'philosophy', [
            'statement' => 'Revisionに固定された理念',
            'statement_explanation' => '正式な説明',
            'sections' => [['title' => '大切なこと', 'body' => '誠実に向き合う']],
        ], 0, null, (string) Str::uuid());
        ManagementDesignItem::query()->whereKey($item->id)->update(['statement' => 'mutable rowだけの変更']);
        app(ManagementDesignWriter::class)->saveOfficial($owner, $organization, 'vision', [
            'statement' => '閲覧できないVision秘密本文',
            'sections' => [],
        ], 0, null, (string) Str::uuid());

        $response = $this->asCompany($viewer, $organization)->get(route('company-context-reader.show'));
        $response->assertOk()
            ->assertSee('Revisionに固定された理念')
            ->assertSee('正式な説明')
            ->assertDontSee('mutable rowだけの変更')
            ->assertDontSee('閲覧できないVision秘密本文')
            ->assertDontSee('data-chapter=&quot;vision&quot;', false);

        $this->asCompany($owner, $organization)->get(route('company-context-reader.show'))->assertOk()
            ->assertSee('閲覧できないVision秘密本文')
            ->assertDontSee('Revisionに固定された理念');
    }

    public function test_owner_manage_capability_does_not_create_reader_view_access(): void
    {
        $organization = $this->organization('owner-no-view');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->asCompany($owner, $organization)->get(route('company-context-reader.show'))->assertForbidden();
    }

    public function test_annual_manage_without_approved_view_does_not_leak_chapter_selector_or_body(): void
    {
        Carbon::setTestNow('2026-10-04 12:00:00 Asia/Tokyo');
        $organization = $this->organization('annual-owner-no-view');
        [$owner, $ownerMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$editor, $editorMembership] = $this->member($organization);
        $this->mdPermissions($owner, $organization, 'philosophy', [$ownerMembership->id => ['can_view' => true]]);
        $policy = $this->annualPolicy($owner, $organization, '2026-01-01', '2026-12-31', 23);
        $this->annualGrants($owner, $policy, $editorMembership);
        $this->approve($editor, $policy, 'Ownerへ漏らしてはいけない年度方針', '2026-01-01', '2026-12-31');

        $this->asCompany($owner, $organization)->get(route('company-context-reader.show'))
            ->assertOk()
            ->assertDontSee('Ownerへ漏らしてはいけない年度方針')
            ->assertDontSee('第23期｜2026年度')
            ->assertDontSee('data-chapter=&quot;annual&quot;', false);
    }

    public function test_default_annual_chapter_is_approved_effective_jst_snapshot_and_never_draft(): void
    {
        Carbon::setTestNow('2026-10-04 12:00:00 Asia/Tokyo');
        $organization = $this->organization('annual-current');
        [$owner, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $group = OrganizationGroup::create(['organization_id' => $organization->id, 'name' => '営業部']);
        $policy = $this->annualPolicy($owner, $organization, '2026-01-01', '2026-12-31', 23);
        $this->annualGrants($owner, $policy, $membership);
        $writer = app(AnnualManagementPolicyWriter::class);
        $saved = $writer->saveDraft($owner, $policy, $this->draft([
            'themes' => [[
                'statement' => '正式な重点テーマ',
                'explanation' => 'テーマの考え方',
                'priorities' => [['statement' => '正式な優先方針', 'explanation' => '優先理由']],
            ]],
            'departments' => [[
                'group_public_id' => $group->public_id,
                'introduction' => '営業部の役割',
                'statements' => [['statement' => '正式な部署方針', 'explanation' => '部署の考え方']],
            ]],
        ]), 0, (string) Str::uuid());
        $preview = $writer->preview($owner, $saved);
        $writer->approve($owner, $saved, 1, null, 0, $preview['snapshot_hash'], false, null, (string) Str::uuid());
        $writer->saveDraft($owner, $saved->fresh(), $this->draft(['policy' => '未承認Draft秘密本文']), 1, (string) Str::uuid());
        $policy->period()->update(['name' => '承認後に変更された期間名', 'fiscal_term_number' => 77]);

        $this->asCompany($owner, $organization)->get(route('company-context-reader.show'))
            ->assertOk()
            ->assertSee('第23期｜2026年度')
            ->assertDontSee('第77期｜承認後に変更された期間名')
            ->assertSee('承認済み / 現在有効')
            ->assertSee('正式な年度方針')
            ->assertSee('正式な重点テーマ')
            ->assertSee('正式な優先方針')
            ->assertSee('正式な部署方針')
            ->assertDontSee('未承認Draft秘密本文');
    }

    public function test_explicit_upcoming_and_ended_selection_is_authorized_and_cross_organization_is_not_an_oracle(): void
    {
        Carbon::setTestNow('2026-10-04 12:00:00 Asia/Tokyo');
        $organization = $this->organization('selector');
        [$owner, $ownerMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$viewer, $viewerMembership] = $this->member($organization);
        $this->mdPermissions($owner, $organization, 'philosophy', [$viewerMembership->id => ['can_view' => true]]);

        $ended = $this->annualPolicy($owner, $organization, '2025-01-01', '2025-12-31', 22);
        $this->annualGrants($owner, $ended, $viewerMembership);
        $this->approve($viewer, $ended, '過去の正式方針', '2025-01-01', '2025-12-31');
        $upcoming = $this->annualPolicy($owner, $organization, '2027-01-01', '2027-12-31', 24);
        $this->annualGrants($owner, $upcoming, $viewerMembership);
        $this->approve($viewer, $upcoming, '来期の正式方針', '2027-01-01', '2027-12-31');

        $this->asCompany($viewer, $organization)->get(route('company-context-reader.show'))
            ->assertOk()->assertDontSee('過去の正式方針')->assertDontSee('来期の正式方針');
        $this->asCompany($viewer, $organization)->get(route('company-context-reader.show', ['annual' => $ended->period->public_id]))
            ->assertOk()->assertSee('過去の正式方針')->assertSee('承認済み / 終了');
        $this->asCompany($viewer, $organization)->get(route('company-context-reader.show', ['annual' => $upcoming->period->public_id]))
            ->assertOk()->assertSee('来期の正式方針')->assertSee('承認済み / 開始前');

        $other = $this->organization('selector-other');
        [$otherOwner, $otherMembership] = $this->member($other, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $foreign = $this->annualPolicy($otherOwner, $other, '2026-01-01', '2026-12-31', 99);
        $this->annualGrants($otherOwner, $foreign, $otherMembership);
        $this->approve($otherOwner, $foreign, '他社秘密方針', '2026-01-01', '2026-12-31');
        $this->asCompany($viewer, $organization)->get(route('company-context-reader.show', ['annual' => $foreign->period->public_id]))
            ->assertNotFound()->assertDontSee('他社秘密方針');
    }

    public function test_reader_composition_is_read_only_and_creates_no_audit_or_revision(): void
    {
        $organization = $this->organization('read-only');
        [$owner, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->mdPermissions($owner, $organization, 'policy', [$membership->id => ['can_view' => true, 'can_edit' => true]]);
        app(ManagementDesignWriter::class)->saveOfficial($owner, $organization, 'policy', [
            'statement' => '読むだけの方針', 'sections' => [],
        ], 0, null, (string) Str::uuid());
        $before = [
            'revisions' => \DB::table('management_design_revisions')->count(),
            'operations' => \DB::table('management_design_operations')->count(),
            'audit' => \DB::table('organization_audit_events')->count(),
        ];

        app(CompanyContextReaderComposer::class)->compose($owner, $organization);
        $this->asCompany($owner, $organization)->get(route('company-context-reader.show'))->assertOk();

        $this->assertSame($before['revisions'], \DB::table('management_design_revisions')->count());
        $this->assertSame($before['operations'], \DB::table('management_design_operations')->count());
        $this->assertSame($before['audit'], \DB::table('organization_audit_events')->count());
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

    private function mdPermissions(User $owner, Organization $organization, string $type, array $grants): void
    {
        app(ManagementDesignPermissionManager::class)->update(
            $owner, $organization, $type, 'explicit', $grants, (string) Str::uuid(),
        );
    }

    private function annualPolicy(User $owner, Organization $organization, string $start, string $end, int $term): AnnualManagementPolicy
    {
        $period = app(ManagementPeriodWriter::class)->register(
            $owner, $organization, substr($start, 0, 4).'年度', $start, $end, (string) Str::uuid(), $term,
        );

        return app(AnnualManagementPolicyPermissionManager::class)->initialize($owner, $organization, $period, (string) Str::uuid());
    }

    private function annualGrants(User $owner, AnnualManagementPolicy $policy, OrganizationUser $membership): void
    {
        app(AnnualManagementPolicyPermissionManager::class)->update(
            $owner, $policy->organization, $policy, AnnualManagementPolicy::VIEW_SCOPE_EXPLICIT,
            [$membership->id => ['can_view_approved' => true, 'can_view_draft' => true, 'can_edit' => true, 'can_approve' => true]],
            (string) Str::uuid(),
        );
    }

    private function approve(User $actor, AnnualManagementPolicy $policy, string $text, string $start, string $end): void
    {
        $writer = app(AnnualManagementPolicyWriter::class);
        $saved = $writer->saveDraft($actor, $policy, $this->draft([
            'period_name' => substr($start, 0, 4).'年度',
            'policy' => $text, 'starts_on' => $start, 'ends_on' => $end,
        ]), 0, (string) Str::uuid());
        $preview = $writer->preview($actor, $saved);
        $writer->approve($actor, $saved, 1, null, 0, $preview['snapshot_hash'], false, null, (string) Str::uuid());
    }

    private function draft(array $override = []): array
    {
        return array_replace_recursive([
            'period_name' => '2026年度', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
            'purpose' => '正式な目的', 'background' => '正式な背景', 'policy' => '正式な年度方針',
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
