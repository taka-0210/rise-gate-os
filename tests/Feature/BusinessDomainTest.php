<?php

namespace Tests\Feature;

use App\Models\BusinessDomain;
use App\Models\BusinessDomainEditorGrant;
use App\Models\BusinessDomainItem;
use App\Models\BusinessDomainRevision;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\AiProjectContextGuard;
use App\Services\BusinessDomain\BusinessDomainAccess;
use App\Services\BusinessDomain\BusinessDomainReferenceService;
use App\Services\BusinessDomain\BusinessDomainSnapshot;
use App\Services\BusinessDomain\BusinessDomainWriter;
use App\Services\Organization\OrganizationAdministration;
use App\Services\Organization\OrganizationAudit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class BusinessDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_start_with_zero_domains_and_create_name_only_without_grant(): void
    {
        $organization = $this->organization('owner-start');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);

        $this->asCompany($owner, $organization)
            ->get(route('company.home'))
            ->assertOk()
            ->assertSee('事業領域')
            ->assertSee('0件');
        $this->assertDatabaseCount('business_domain_editor_grants', 0);

        $requestId = (string) Str::uuid();
        $payload = ['request_id' => $requestId, 'name' => '製造事業'];
        $this->asCompany($owner, $organization)->post(route('business-domains.store'), $payload)->assertRedirect();
        $this->asCompany($owner, $organization)->post(route('business-domains.store'), $payload)->assertRedirect();

        $domain = BusinessDomain::sole();
        $this->assertSame(1, $domain->version);
        $this->assertSame(BusinessDomain::STATUS_ACTIVE, $domain->status);
        $this->assertDatabaseCount('business_domains', 1);
        $this->assertDatabaseCount('business_domain_revisions', 1);
        $this->assertDatabaseCount('business_domain_operations', 1);
        $this->assertDatabaseCount('organization_audit_events', 1);
        $this->assertSame('Asia/Tokyo', config('app.timezone'));
        $revision = $domain->revisions()->firstOrFail();
        $this->assertSame('Asia/Tokyo', $revision->changed_at->timezone->getName());
        $this->assertSame('organization_self_reported', $revision->snapshot['source']);
    }

    public function test_active_staff_view_current_values_but_only_owner_or_granted_staff_can_edit_and_view_history(): void
    {
        $organization = $this->organization('permissions');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$admin, $adminMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_ADMIN);
        [$member, $memberMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $domain = $this->createDomain($owner, $organization, ['name' => 'ホテル事業']);

        foreach ([$admin, $member] as $staff) {
            $this->asCompany($staff, $organization)->get(route('business-domains.index'))->assertOk()->assertSee('ホテル事業');
            $this->asCompany($staff, $organization)->get(route('business-domains.show', $domain))->assertOk()->assertDontSee('Revision履歴');
            $this->asCompany($staff, $organization)->get(route('business-domains.edit', $domain))->assertForbidden();
            $this->asCompany($staff, $organization)->get(route('business-domains.revisions.show', [$domain, 1]))->assertForbidden();
        }

        $this->asCompany($admin, $organization)->get(route('business-domains.editors'))->assertForbidden();
        $this->asCompany($owner, $organization)->post(route('business-domains.editors.grant', $adminMembership), [
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->asCompany($admin, $organization)->get(route('business-domains.edit', $domain))->assertOk();
        $this->asCompany($admin, $organization)->get(route('business-domains.revisions.show', [$domain, 1]))->assertOk();

        $memberMembership->update(['role' => OrganizationUser::ROLE_OWNER]);
        $this->asCompany($member, $organization)->get(route('business-domains.edit', $domain))->assertForbidden();
        $this->assertSame([], $memberMembership->fresh()->permissions ?? []);
    }

    public function test_only_active_owner_can_grant_and_can_revoke_a_stopped_membership_without_resurrecting_it(): void
    {
        $organization = $this->organization('grants');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$admin] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_ADMIN);
        [$member, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $requestId = (string) Str::uuid();

        $this->asCompany($admin, $organization)->post(route('business-domains.editors.grant', $membership), [
            'request_id' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->asCompany($owner, $organization)->post(route('business-domains.editors.grant', $membership), [
            'request_id' => $requestId,
        ])->assertRedirect();
        $this->asCompany($owner, $organization)->post(route('business-domains.editors.grant', $membership), [
            'request_id' => $requestId,
        ])->assertRedirect();
        $this->assertDatabaseCount('business_domain_editor_grants', 1);
        $this->asCompany($owner, $organization)->get(route('business-domains.editors'))
            ->assertOk()
            ->assertSee($member->name)
            ->assertSee('編集担当');

        $membership->update(['membership_status' => OrganizationUser::STATUS_SUSPENDED]);
        $this->asCompany($owner, $organization)->delete(route('business-domains.editors.revoke', $membership), [
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect();
        $membership->update(['membership_status' => OrganizationUser::STATUS_ACTIVE]);

        $this->assertNotNull(BusinessDomainEditorGrant::sole()->revoked_at);
        $this->assertFalse(app(BusinessDomainAccess::class)->canEdit($member, $organization));
        $this->assertDatabaseHas('organization_audit_events', [
            'organization_id' => $organization->id,
            'event' => 'business_domain.editor_revoke',
            'outcome' => OrganizationAuditEvent::OUTCOME_SUCCESS,
        ]);
    }

    public function test_role_changes_are_reauthorized_without_creating_or_restoring_grants(): void
    {
        $organization = $this->organization('role-change');
        [$firstOwner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$secondOwner, $secondMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $domain = $this->createDomain($secondOwner, $organization, ['name' => '役割再判定']);

        app(OrganizationAdministration::class)->updateRole(
            $firstOwner,
            $organization,
            $secondMembership,
            OrganizationUser::ORGANIZATION_ROLE_MEMBER,
        );
        $this->assertFalse(app(BusinessDomainAccess::class)->canEdit($secondOwner, $organization));
        $this->assertDatabaseCount('business_domain_editor_grants', 0);
        $this->asCompany($secondOwner, $organization)->get(route('business-domains.edit', $domain))->assertForbidden();

        app(OrganizationAdministration::class)->updateRole(
            $firstOwner,
            $organization,
            $secondMembership,
            OrganizationUser::ORGANIZATION_ROLE_OWNER,
        );
        $this->assertTrue(app(BusinessDomainAccess::class)->canEdit($secondOwner, $organization));
        $this->assertDatabaseCount('business_domain_editor_grants', 0);
    }

    public function test_five_axes_items_attributes_and_four_industries_share_one_flat_schema(): void
    {
        $organization = $this->organization('fixtures');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $fixtures = [
            ['製造', 'product', '精密部品'],
            ['ホテル', 'location', '本館'],
            ['飲食', 'brand', '季節食堂'],
            ['ライズアップ', 'service', '経営伴走'],
        ];

        foreach ($fixtures as [$name, $kind, $itemName]) {
            $domain = $this->createDomain($owner, $organization, [
                'name' => $name,
                'what_summary' => '提供内容', 'who_summary' => '対象顧客',
                'value_proposition' => '提供価値', 'geographic_scope_summary' => '提供地域',
                'market_position_summary' => '市場での位置', 'self_recognized_strengths' => '自社認識',
                'items' => [[
                    'kind' => $kind, 'name' => $itemName, 'description' => '明細説明',
                    'attributes' => [[
                        'axis' => 'value', 'label' => '特徴', 'value_text' => '顧客に届ける特徴',
                    ]],
                ]],
            ]);
            $this->assertCount(1, $domain->items);
            $this->assertCount(1, $domain->items->first()->attributes);
        }

        $this->assertDatabaseCount('business_domains', 4);
        $this->assertDatabaseCount('business_domain_items', 4);
        $this->assertDatabaseCount('business_domain_item_attributes', 4);
        $this->assertDatabaseCount('business_domain_revisions', 4);
        $this->assertDatabaseMissing('business_domains', ['name' => 'SWOT']);
    }

    public function test_update_preserves_stable_ids_archives_removed_items_and_rejects_stale_or_foreign_child_ids(): void
    {
        $organization = $this->organization('update');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $domain = $this->createDomain($owner, $organization, [
            'name' => '既存事業',
            'items' => [[
                'kind' => 'service', 'name' => '既存サービス',
                'attributes' => [['axis' => 'who', 'label' => '顧客', 'value_text' => '法人']],
            ]],
        ]);
        $item = $domain->items->first();
        $attribute = $item->attributes->first();

        $updated = app(BusinessDomainWriter::class)->update($owner, $organization, $domain, [
            'name' => '更新事業',
            'items' => [[
                'public_id' => $item->public_id, 'kind' => 'service', 'name' => '更新サービス',
                'attributes' => [[
                    'public_id' => $attribute->public_id, 'axis' => 'who', 'label' => '顧客', 'value_text' => '中小企業',
                ]],
            ]],
        ], 1, '顧客像を具体化', (string) Str::uuid());

        $this->assertSame(2, $updated->version);
        $this->assertSame($item->public_id, $updated->items->first()->public_id);
        $this->assertSame($attribute->public_id, $updated->items->first()->attributes->first()->public_id);
        $this->expectException(ValidationException::class);
        app(BusinessDomainWriter::class)->update(
            $owner, $organization, $updated, ['name' => 'stale', 'items' => []], 1, '古い画面', (string) Str::uuid(),
        );
    }

    public function test_update_retry_is_idempotent_and_audit_never_copies_domain_content_or_reason(): void
    {
        $organization = $this->organization('idempotency');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $domain = $this->createDomain($owner, $organization, ['name' => '初期名称']);
        $requestId = (string) Str::uuid();
        $payload = ['name' => '非公開文字列を含む名称', 'description' => '本文固有値', 'items' => []];

        $first = app(BusinessDomainWriter::class)->update(
            $owner, $organization, $domain, $payload, 1, '判断理由固有値', $requestId,
        );
        $retry = app(BusinessDomainWriter::class)->update(
            $owner, $organization, $domain, $payload, 1, '判断理由固有値', $requestId,
        );

        $this->assertSame(2, $first->version);
        $this->assertSame(2, $retry->version);
        $this->assertDatabaseCount('business_domain_operations', 2);
        $this->assertDatabaseCount('business_domain_revisions', 2);
        $this->assertDatabaseCount('organization_audit_events', 2);
        $serializedAudit = OrganizationAuditEvent::query()->get()->toJson(JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('本文固有値', $serializedAudit);
        $this->assertStringNotContainsString('判断理由固有値', $serializedAudit);
        $this->assertSame('判断理由固有値', $first->revisions()->where('revision_no', 2)->firstOrFail()->change_reason);

        try {
            app(BusinessDomainWriter::class)->update(
                $owner, $organization, $domain, ['name' => '別内容', 'items' => []], 1, '別理由', $requestId,
            );
            $this->fail('A request ID must not accept a different payload.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request_id', $exception->errors());
        }
    }

    public function test_removed_items_are_archived_and_cross_domain_child_move_is_rejected(): void
    {
        $organization = $this->organization('children');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $first = $this->createDomain($owner, $organization, ['name' => 'A', 'items' => [[
            'kind' => 'product', 'name' => 'A商品', 'attributes' => [],
        ]]]);
        $second = $this->createDomain($owner, $organization, ['name' => 'B', 'items' => [[
            'kind' => 'product', 'name' => 'B商品', 'attributes' => [],
        ]]]);
        $firstItem = $first->items->first();

        app(BusinessDomainWriter::class)->update(
            $owner, $organization, $first, ['name' => 'A', 'items' => []], 1, '明細を整理', (string) Str::uuid(),
        );
        $this->assertSame(BusinessDomainItem::STATUS_ARCHIVED, $firstItem->fresh()->status);

        try {
            app(BusinessDomainWriter::class)->update($owner, $organization, $second, [
                'name' => 'B', 'items' => [[
                    'public_id' => $firstItem->public_id, 'kind' => 'product', 'name' => '移動', 'attributes' => [],
                ]],
            ], 1, '誤った移動', (string) Str::uuid());
            $this->fail('Cross-domain item move must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.public_id', $exception->errors());
        }
        $this->assertSame($first->id, $firstItem->fresh()->business_domain_id);
    }

    public function test_archive_and_reopen_keep_current_data_and_immutable_revision_history(): void
    {
        $organization = $this->organization('archive');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $domain = $this->createDomain($owner, $organization, ['name' => '飲食事業']);
        $domain = app(BusinessDomainWriter::class)->archive(
            $owner, $organization, $domain, 1, '今期は休止', (string) Str::uuid(),
        );
        $this->assertSame(BusinessDomain::STATUS_ARCHIVED, $domain->status);
        $this->assertNotNull($domain->archived_at);
        $this->asCompany($owner, $organization)->get(route('business-domains.index'))->assertDontSee('飲食事業');
        $this->asCompany($owner, $organization)->get(route('business-domains.index', ['status' => 'archived']))->assertSee('飲食事業');
        $this->asCompany($owner, $organization)->get(route('business-domains.show', $domain))->assertOk();

        $domain = app(BusinessDomainWriter::class)->reopen(
            $owner, $organization, $domain, 2, '提供を再開', (string) Str::uuid(),
        );
        $this->assertSame(3, $domain->version);
        $this->assertDatabaseCount('business_domain_revisions', 3);
        $revision = BusinessDomainRevision::query()->where('revision_no', 1)->firstOrFail();
        $this->expectException(LogicException::class);
        $revision->update(['change_reason' => '書換']);
    }

    public function test_transaction_rolls_back_current_values_revision_operation_and_audit_on_fault(): void
    {
        $organization = $this->organization('atomic');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        foreach (['after_current_values', 'after_revision', 'after_operation', 'after_audit'] as $faultPoint) {
            $writer = new class(app(BusinessDomainAccess::class), app(BusinessDomainSnapshot::class), app(OrganizationAudit::class), $faultPoint) extends BusinessDomainWriter
            {
                public function __construct(
                    BusinessDomainAccess $access,
                    BusinessDomainSnapshot $snapshots,
                    OrganizationAudit $audit,
                    private readonly string $faultPoint,
                ) {
                    parent::__construct($access, $snapshots, $audit);
                }

                protected function checkpoint(string $name): void
                {
                    if ($name === $this->faultPoint) {
                        throw new RuntimeException('fault injection '.$name);
                    }
                }
            };

            try {
                $writer->create($owner, $organization, ['name' => 'Rollback target', 'items' => []], (string) Str::uuid());
                $this->fail('Fault injection must abort the transaction.');
            } catch (RuntimeException $exception) {
                $this->assertSame('fault injection '.$faultPoint, $exception->getMessage());
            }

            $this->assertDatabaseCount('business_domains', 0);
            $this->assertDatabaseCount('business_domain_revisions', 0);
            $this->assertDatabaseCount('business_domain_operations', 0);
            $this->assertDatabaseCount('organization_audit_events', 0);
        }
    }

    public function test_tenant_inactive_and_stopped_boundaries_hide_names_counts_history_and_writes(): void
    {
        $organization = $this->organization('tenant-a');
        [$owner, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $domain = $this->createDomain($owner, $organization, ['name' => '機密ではない社内共有領域']);
        $other = $this->organization('tenant-b');
        [$otherOwner] = $this->member($other, OrganizationUser::ORGANIZATION_ROLE_OWNER);

        $this->asCompany($otherOwner, $other)->get(route('business-domains.show', $domain))->assertNotFound()->assertDontSee('社内共有領域');
        $this->asCompany($otherOwner, $other)->get(route('business-domains.revisions.show', [$domain, 1]))->assertNotFound();

        $membership->update(['membership_status' => OrganizationUser::STATUS_SUSPENDED, 'access_epoch' => 2]);
        $this->actingAs($owner)->withSession($this->companySession($organization))
            ->get(route('business-domains.index'))->assertRedirect(route('companies.index'));
        $this->assertThrows(
            fn () => app(BusinessDomainAccess::class)->authorizeView($owner, $organization),
            AuthorizationException::class,
        );

        $membership->update(['membership_status' => OrganizationUser::STATUS_ACTIVE]);
        $owner->update(['is_active' => false]);
        $this->assertThrows(
            fn () => app(BusinessDomainAccess::class)->authorizeView($owner, $organization),
            AuthorizationException::class,
        );
    }

    public function test_internal_reference_contract_requires_explicit_authorized_domain_and_editor_for_old_revision(): void
    {
        $organization = $this->organization('reference');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$member] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $domain = $this->createDomain($owner, $organization, ['name' => '参照事業', 'what_summary' => '相談支援']);
        $service = app(BusinessDomainReferenceService::class);

        $current = $service->get($member, $organization, $domain->public_id, 'Project候補表示');
        $this->assertSame('company_os.business_domain_reference', $current['contract']);
        $this->assertSame(1, $current['contract_version']);
        $this->assertSame('organization_self_reported', $current['source']);
        $this->assertSame($domain->public_id, $current['domain_public_id']);

        $this->assertThrows(
            fn () => $service->get($member, $organization, $domain->public_id, '過去版', 1),
            AuthorizationException::class,
        );
        $this->assertSame(1, $service->get($owner, $organization, $domain->public_id, '過去版', 1)['revision']);

        $other = $this->organization('reference-other');
        [$otherOwner] = $this->member($other, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->assertThrows(
            fn () => $service->get($otherOwner, $other, $domain->public_id, '他社参照'),
            ModelNotFoundException::class,
        );
    }

    public function test_search_is_org_scoped_safe_and_ai_categories_are_not_expanded(): void
    {
        $organization = $this->organization('search');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->createDomain($owner, $organization, [
            'name' => '<script>alert(1)</script>', 'who_summary' => '地域の製造業',
            'items' => [['kind' => 'service', 'name' => '伴走', 'attributes' => [[
                'axis' => 'position', 'label' => '独自性', 'value_text' => '現場密着',
            ]]]],
        ]);
        $other = $this->organization('search-other');
        [$otherOwner] = $this->member($other, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->createDomain($otherOwner, $other, ['name' => '他社だけの領域']);

        $this->asCompany($owner, $organization)->get(route('business-domains.index', ['q' => '現場密着']))
            ->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('他社だけの領域');
        $this->assertNotContains('business_domains', AiProjectContextGuard::SCOPE_ONE_CATEGORIES);
        $this->assertSame(['project_metadata', 'roadmaps', 'improvements', 'tasks'], AiProjectContextGuard::SCOPE_ONE_CATEGORIES);
    }

    public function test_validation_limits_reject_oversized_nested_payload_without_partial_write(): void
    {
        $organization = $this->organization('limits');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $items = [];
        for ($i = 0; $i < 101; $i++) {
            $items[] = ['kind' => 'other', 'name' => 'item-'.$i, 'attributes' => []];
        }

        $this->asCompany($owner, $organization)->post(route('business-domains.store'), [
            'request_id' => (string) Str::uuid(), 'name' => 'too many', 'items' => $items,
        ])->assertSessionHasErrors('items');
        $this->assertDatabaseCount('business_domains', 0);
        $this->assertDatabaseCount('business_domain_operations', 0);
    }

    private function createDomain(User $actor, Organization $organization, array $input): BusinessDomain
    {
        $input['items'] ??= [];

        return app(BusinessDomainWriter::class)->create($actor, $organization, $input, (string) Str::uuid());
    }

    private function organization(string $slug): Organization
    {
        return Organization::create([
            'name' => 'Organization '.$slug,
            'slug' => $slug.'-'.strtolower((string) Str::ulid()),
        ]);
    }

    private function member(Organization $organization, string $role): array
    {
        $user = User::factory()->create();
        $membership = OrganizationUser::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role === OrganizationUser::ORGANIZATION_ROLE_OWNER ? OrganizationUser::ROLE_OWNER : OrganizationUser::ROLE_MEMBER,
            'organization_role' => $role,
            'membership_status' => OrganizationUser::STATUS_ACTIVE,
            'access_epoch' => 1,
            'permissions' => [],
            'joined_at' => now(),
        ]);

        return [$user, $membership];
    }

    private function asCompany(User $user, Organization $organization): static
    {
        return $this->actingAs($user)->withSession($this->companySession($organization));
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
}
