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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function test_index_renders_a_domain_with_description_without_server_error(): void
    {
        $organization = $this->organization('description-index');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $description = '実利用確認で一覧へ表示する概要です。';
        $this->createDomain($owner, $organization, [
            'name' => '概要あり事業',
            'description' => $description,
        ]);

        $this->asCompany($owner, $organization)
            ->get(route('business-domains.index'))
            ->assertOk()
            ->assertSee('概要あり事業')
            ->assertSee($description);
    }

    public function test_direction_is_optional_validated_and_recorded_in_existing_revision_history(): void
    {
        $organization = $this->organization('direction');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $domain = $this->createDomain($owner, $organization, ['name' => '方向性確認事業']);

        $this->assertTrue(Schema::hasColumns('business_domains', ['direction', 'direction_memo', 'display_order']));
        $this->assertNull($domain->direction);
        $this->assertNull($domain->direction_memo);
        $this->assertSame(2, $domain->revisions()->firstOrFail()->snapshot_schema_version);
        $this->assertNull($domain->revisions()->firstOrFail()->snapshot['domain']['direction']);

        foreach (array_keys(BusinessDomain::DIRECTIONS) as $direction) {
            $memo = $direction.' の判断メモ';
            $this->asCompany($owner, $organization)->put(route('business-domains.update', $domain), [
                'request_id' => (string) Str::uuid(),
                'expected_version' => $domain->version,
                'name' => $domain->name,
                'direction' => $direction,
                'direction_memo' => $memo,
                'change_reason' => '今後の方向性を更新',
            ])->assertRedirect(route('business-domains.show', $domain));
            $domain->refresh();
            $this->assertSame($direction, $domain->direction);
            $this->assertSame($memo, $domain->direction_memo);
        }

        $this->assertSame(6, $domain->version);
        $this->assertSame(BusinessDomain::DIRECTION_GROWTH, $domain->revisions()->where('revision_no', 2)->firstOrFail()->snapshot['domain']['direction']);
        $this->assertSame(BusinessDomain::DIRECTION_EXIT_PLANNED, $domain->revisions()->where('revision_no', 6)->firstOrFail()->snapshot['domain']['direction']);
        $this->asCompany($owner, $organization)->get(route('business-domains.revisions.show', [$domain, 2]))
            ->assertOk()
            ->assertSee('成長・拡大')
            ->assertSee('growth の判断メモ');

        $this->asCompany($owner, $organization)->put(route('business-domains.update', $domain), [
            'request_id' => (string) Str::uuid(),
            'expected_version' => $domain->version,
            'name' => $domain->name,
            'direction' => 'unsupported',
            'change_reason' => '不正値',
        ])->assertSessionHasErrors('direction');
        $this->assertSame(6, $domain->fresh()->version);
    }

    public function test_index_uses_count_tabs_and_keeps_hidden_search_and_all_status_backend_contracts(): void
    {
        $organization = $this->organization('index-ux');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $active = $this->createDomain($owner, $organization, ['name' => '利用中の対象事業']);
        $archived = $this->createDomain($owner, $organization, ['name' => '保管対象事業']);
        $archived = app(BusinessDomainWriter::class)->archive(
            $owner, $organization, $archived, 1, '一覧切替確認', (string) Str::uuid(),
        );

        $this->asCompany($owner, $organization)->get(route('business-domains.index'))
            ->assertOk()
            ->assertSeeInOrder(['利用中', '1', '保管済み', '1'])
            ->assertSee($active->name)
            ->assertDontSee($archived->name)
            ->assertDontSee('domain-q')
            ->assertDontSee('絞り込む')
            ->assertDontSee('value="all"', false)
            ->assertDontSee('Revision 1');
        $this->asCompany($owner, $organization)->get(route('business-domains.index', ['status' => 'archived']))
            ->assertOk()->assertSee($archived->name)->assertDontSee($active->name);
        $this->asCompany($owner, $organization)->get(route('business-domains.index', ['status' => 'all']))
            ->assertOk()->assertSee($active->name)->assertSee($archived->name);
        $this->asCompany($owner, $organization)->get(route('business-domains.index', ['status' => 'all', 'q' => '利用中の対象']))
            ->assertOk()->assertSee($active->name)->assertDontSee($archived->name);
    }

    public function test_reorder_is_atomic_idempotent_audited_and_does_not_create_content_revision(): void
    {
        $organization = $this->organization('reorder');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$admin, $adminMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_ADMIN);
        [$member] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $first = $this->createDomain($owner, $organization, ['name' => '第一事業']);
        $second = $this->createDomain($owner, $organization, ['name' => '第二事業']);
        $third = $this->createDomain($owner, $organization, ['name' => '第三事業']);
        $requestId = (string) Str::uuid();
        $revisionCount = BusinessDomainRevision::count();

        $this->asCompany($member, $organization)->post(route('business-domains.move', $third), [
            'request_id' => (string) Str::uuid(), 'direction' => 'up',
        ])->assertForbidden();
        $other = $this->organization('reorder-other');
        [$otherOwner] = $this->member($other, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->asCompany($otherOwner, $other)->post(route('business-domains.move', $third), [
            'request_id' => (string) Str::uuid(), 'direction' => 'up',
        ])->assertNotFound();
        $payload = ['request_id' => $requestId, 'direction' => 'up'];
        $this->asCompany($owner, $organization)->post(route('business-domains.move', $third), $payload)->assertRedirect();
        $this->asCompany($owner, $organization)->post(route('business-domains.move', $third), $payload)->assertRedirect();

        $ordered = BusinessDomain::query()->where('organization_id', $organization->id)
            ->orderBy('display_order')->orderBy('id')->get();
        $this->assertSame([$first->id, $third->id, $second->id], $ordered->pluck('id')->all());
        $this->assertSame([10, 20, 30], $ordered->pluck('display_order')->all());
        $this->assertSame($revisionCount, BusinessDomainRevision::count());
        $this->assertSame(1, $third->fresh()->version);
        $operation = DB::table('business_domain_operations')->where('operation', 'reorder')->sole();
        $metadata = json_decode($operation->result_metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($third->public_id, $metadata['domain_public_id']);
        $this->assertCount(3, $metadata['before_order']);
        $this->assertCount(3, $metadata['after_order']);
        $this->assertDatabaseHas('organization_audit_events', [
            'organization_id' => $organization->id,
            'actor_user_id' => $owner->id,
            'event' => 'business_domain.reorder',
            'outcome' => OrganizationAuditEvent::OUTCOME_SUCCESS,
        ]);
        $this->asCompany($owner, $organization)->get(route('business-domains.index'))
            ->assertOk()->assertSeeInOrder(['第一事業', '第三事業', '第二事業']);

        $this->asCompany($owner, $organization)->post(route('business-domains.editors.grant', $adminMembership), [
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->asCompany($admin, $organization)->post(route('business-domains.move', $third), [
            'request_id' => (string) Str::uuid(), 'direction' => 'down',
        ])->assertRedirect();
        $this->assertSame(
            [$first->id, $second->id, $third->id],
            BusinessDomain::query()->where('organization_id', $organization->id)
                ->orderBy('display_order')->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame($revisionCount, BusinessDomainRevision::count());
    }

    public function test_reorder_transaction_rolls_back_order_operation_and_audit_on_fault(): void
    {
        $organization = $this->organization('reorder-atomic');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $first = $this->createDomain($owner, $organization, ['name' => '第一事業']);
        $second = $this->createDomain($owner, $organization, ['name' => '第二事業']);
        $operationCount = DB::table('business_domain_operations')->count();
        $auditCount = OrganizationAuditEvent::count();
        $writer = new class(app(BusinessDomainAccess::class), app(BusinessDomainSnapshot::class), app(OrganizationAudit::class)) extends BusinessDomainWriter
        {
            protected function checkpoint(string $name): void
            {
                if ($name === 'after_current_values') {
                    throw new RuntimeException('reorder fault');
                }
            }
        };

        try {
            $writer->move($owner, $organization, $second, 'up', (string) Str::uuid());
            $this->fail('Reorder fault must abort the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('reorder fault', $exception->getMessage());
        }

        $this->assertSame(
            [$first->id, $second->id],
            BusinessDomain::query()->where('organization_id', $organization->id)
                ->orderBy('display_order')->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame($operationCount, DB::table('business_domain_operations')->count());
        $this->assertSame($auditCount, OrganizationAuditEvent::count());
        $this->assertSame(2, BusinessDomainRevision::count());
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

    public function test_pux_b_read_is_editorial_and_does_not_load_or_render_management_history(): void
    {
        $organization = $this->organization('pux-b-read');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $domain = $this->createDomain($owner, $organization, [
            'name' => '地域伴走事業',
            'description' => '地域企業の意思決定を支える事業です。',
            'what_summary' => '経営支援',
        ]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->asCompany($owner, $organization)->get(route('business-domains.show', $domain));

        $response->assertOk()
            ->assertSee('地域伴走事業')
            ->assertSee('地域企業の意思決定を支える事業です。')
            ->assertSee('事業の輪郭')
            ->assertSee(route('business-domains.manage.show', $domain), false)
            ->assertDontSee('Revision履歴')
            ->assertDontSee('name="change_reason"', false)
            ->assertDontSee('name="expected_version"', false)
            ->assertDontSee(route('business-domains.archive', $domain), false);
        $this->assertFalse(
            collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'business_domain_revisions')),
            'Normal Read must not query business_domain_revisions.',
        );
    }

    public function test_pux_b_manage_routes_are_static_authorized_and_hold_management_controls(): void
    {
        $organization = $this->organization('pux-b-manage');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$admin, $adminMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_ADMIN);
        [$member] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $domain = $this->createDomain($owner, $organization, ['name' => '管理対象事業']);

        $this->asCompany($owner, $organization)->get('/company/business-domains/manage')
            ->assertOk()
            ->assertSee('事業領域の管理')
            ->assertSee('管理対象事業');
        $this->asCompany($owner, $organization)->get(route('business-domains.manage.show', $domain))
            ->assertOk()
            ->assertSee('Revision履歴')
            ->assertSee('name="change_reason"', false)
            ->assertSee(route('business-domains.archive', $domain), false);

        $this->asCompany($member, $organization)->get(route('business-domains.manage'))->assertForbidden();
        $this->asCompany($member, $organization)->get(route('business-domains.manage.show', $domain))->assertForbidden();

        $this->asCompany($owner, $organization)->post(route('business-domains.editors.grant', $adminMembership), [
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->asCompany($admin, $organization)->get(route('business-domains.manage'))->assertOk();
        $this->asCompany($admin, $organization)->get(route('business-domains.manage.show', $domain))->assertOk();
        $this->asCompany($admin, $organization)->get(route('business-domains.editors'))->assertForbidden();
    }

    public function test_pux_b_read_omits_empty_sections_and_presents_existing_content_without_copying_it(): void
    {
        $organization = $this->organization('pux-b-content');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $nameOnly = $this->createDomain($owner, $organization, ['name' => '名称だけの事業']);
        $full = $this->createDomain($owner, $organization, [
            'name' => '<事業&名称>',
            'description' => '原文の概要',
            'who_summary' => '地域の中小企業',
            'self_recognized_strengths' => '現場に入り込む力',
            'direction' => BusinessDomain::DIRECTION_EXIT_PLANNED,
            'direction_memo' => '段階的な統合を検討する',
            'items' => [[
                'kind' => 'customer_segment',
                'name' => '長期顧客',
                'description' => '既存の顧客層',
                'attributes' => [[
                    'axis' => 'value',
                    'label' => '提供価値',
                    'value_text' => '<script>alert(1)</script>',
                ]],
            ]],
        ]);

        $this->asCompany($owner, $organization)->get(route('business-domains.show', $nameOnly))
            ->assertOk()
            ->assertSee('名称だけの事業')
            ->assertDontSee('事業の輪郭')
            ->assertDontSee('自社認識の強み')
            ->assertDontSee('今後の方向性')
            ->assertDontSee('この事業を形づくるもの')
            ->assertDontSee('未登録');

        $this->asCompany($owner, $organization)->get(route('business-domains.show', $full))
            ->assertOk()
            ->assertSee('&lt;事業&amp;名称&gt;', false)
            ->assertSeeInOrder(['WHO', '誰に届けるか', '地域の中小企業'])
            ->assertSee('終了予定')
            ->assertSee('段階的な統合を検討する')
            ->assertSee('顧客層')
            ->assertSee('VALUE / 提供価値')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);

        $this->assertSame('原文の概要', $full->fresh()->description);
        $this->assertDatabaseCount('business_domains', 2);
        $this->assertDatabaseCount('business_domain_revisions', 2);
    }

    public function test_pux_b_presentation_story_uses_saved_content_with_accessible_progressive_disclosure(): void
    {
        $organization = $this->organization('pux-b-story');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $domain = $this->createDomain($owner, $organization, [
            'name' => '地域共創事業',
            'description' => '地域企業の次の一歩を支える事業です。',
            'what_summary' => '経営と実行をつなぐ支援',
            'who_summary' => '地域で働く人たち',
            'value_proposition' => '判断を続けられる行動へ変える',
            'geographic_scope_summary' => '日本国内',
            'market_position_summary' => '実装まで並走する経営パートナー',
            'self_recognized_strengths' => '経営とシステムを同時に理解する力',
            'direction' => BusinessDomain::DIRECTION_STRENGTHEN,
            'direction_memo' => '品質と再現性を高める。',
            'items' => [
                ['kind' => 'service', 'name' => '伴走支援', 'attributes' => []],
                ['kind' => 'brand', 'name' => 'Company OS', 'attributes' => []],
            ],
        ]);

        $response = $this->asCompany($owner, $organization)->get(route('business-domains.show', $domain));

        $response->assertOk()
            ->assertSee('data-company-context-hero', false)
            ->assertSeeInOrder([
                '地域共創事業',
                '地域企業の次の一歩を支える事業です。',
                'THE VALUE WE CREATE',
                '判断を続けられる行動へ変える',
                'BUSINESS OUTLINE',
                '事業の輪郭',
                'SELF-RECOGNIZED STRENGTHS',
                '経営とシステムを同時に理解する力',
                'THE BUSINESS, IN PRACTICE',
                '伴走支援',
                'Company OS',
                'WHERE WE GO NEXT',
                '品質と再現性を高める。',
            ])
            ->assertSee('role="tablist"', false)
            ->assertSee('aria-label="事業の5つの視点"', false)
            ->assertSee('role="tabpanel"', false)
            ->assertDontSee('role="tabpanel" hidden', false)
            ->assertSee('company-context-tools', false)
            ->assertSee('prefers-reduced-motion: reduce', false)
            ->assertSee('IntersectionObserver', false)
            ->assertDontSee('name="change_reason"', false)
            ->assertDontSee('Revision履歴');

        $this->assertSame('地域企業の次の一歩を支える事業です。', $domain->fresh()->description);
        $this->assertDatabaseCount('business_domain_revisions', 1);
    }

    public function test_pux_b_read_and_printable_gets_do_not_mutate_domain_history_or_audit(): void
    {
        $organization = $this->organization('pux-b-no-write');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $domain = $this->createDomain($owner, $organization, ['name' => '読取専用事業']);
        $beforeDomain = $domain->fresh();
        $before = [
            'domain' => [
                'version' => $beforeDomain->version,
                'display_order' => $beforeDomain->display_order,
                'status' => $beforeDomain->status,
                'updated_at' => $beforeDomain->updated_at?->toISOString(),
            ],
            'revisions' => BusinessDomainRevision::count(),
            'operations' => DB::table('business_domain_operations')->count(),
            'audits' => OrganizationAuditEvent::count(),
        ];

        $this->asCompany($owner, $organization)->get(route('business-domains.index'))->assertOk();
        $this->asCompany($owner, $organization)->get(route('business-domains.show', $domain))->assertOk();

        $afterDomain = $domain->fresh();
        $this->assertSame($before['domain'], [
            'version' => $afterDomain->version,
            'display_order' => $afterDomain->display_order,
            'status' => $afterDomain->status,
            'updated_at' => $afterDomain->updated_at?->toISOString(),
        ]);
        $this->assertSame($before['revisions'], BusinessDomainRevision::count());
        $this->assertSame($before['operations'], DB::table('business_domain_operations')->count());
        $this->assertSame($before['audits'], OrganizationAuditEvent::count());
    }

    public function test_pux_b_management_operations_return_to_manage_without_changing_writer_contract(): void
    {
        $organization = $this->organization('pux-b-redirect');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $first = $this->createDomain($owner, $organization, ['name' => '第一事業']);
        $second = $this->createDomain($owner, $organization, ['name' => '第二事業']);

        $this->asCompany($owner, $organization)->post(route('business-domains.move', $second), [
            'request_id' => (string) Str::uuid(),
            'direction' => 'up',
        ])->assertRedirect(route('business-domains.manage', ['status' => 'active']));

        $this->asCompany($owner, $organization)->post(route('business-domains.archive', $first), [
            'request_id' => (string) Str::uuid(),
            'expected_version' => $first->version,
            'change_reason' => '管理画面から保管',
        ])->assertRedirect(route('business-domains.manage.show', $first));

        $first->refresh();
        $this->asCompany($owner, $organization)->get(route('business-domains.show', $first))
            ->assertOk()
            ->assertSee('保管時点の現在値');
        $this->asCompany($owner, $organization)->get(route('business-domains.edit', $first))->assertStatus(409);

        $this->asCompany($owner, $organization)->post(route('business-domains.reopen', $first), [
            'request_id' => (string) Str::uuid(),
            'expected_version' => $first->version,
            'change_reason' => '管理画面から再開',
        ])->assertRedirect(route('business-domains.manage.show', $first));
    }

    public function test_pux_b_tenant_boundary_hides_read_manage_and_history_for_other_organization(): void
    {
        $organization = $this->organization('pux-b-tenant');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $domain = $this->createDomain($owner, $organization, ['name' => '境界内事業']);
        $other = $this->organization('pux-b-other');
        [$otherOwner] = $this->member($other, OrganizationUser::ORGANIZATION_ROLE_OWNER);

        $this->asCompany($otherOwner, $other)->get(route('business-domains.show', $domain))
            ->assertNotFound()->assertDontSee('境界内事業');
        $this->asCompany($otherOwner, $other)->get(route('business-domains.manage.show', $domain))
            ->assertNotFound()->assertDontSee('境界内事業');
        $this->asCompany($otherOwner, $other)->get(route('business-domains.revisions.show', [$domain, 1]))
            ->assertNotFound()->assertDontSee('境界内事業');
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
