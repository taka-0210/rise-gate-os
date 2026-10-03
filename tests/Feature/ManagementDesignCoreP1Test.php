<?php

namespace Tests\Feature;

use App\Models\ManagementDesignItem;
use App\Models\ManagementDesignRevision;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\ManagementDesign\ManagementDesignAccess;
use App\Services\ManagementDesign\ManagementDesignPermissionManager;
use App\Services\ManagementDesign\ManagementDesignSnapshot;
use App\Services\ManagementDesign\ManagementDesignWriter;
use App\Services\Organization\OrganizationAudit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ManagementDesignCoreP1Test extends TestCase
{
    use RefreshDatabase;

    public function test_owner_manages_permissions_but_has_no_automatic_view_or_edit_bypass(): void
    {
        $organization = $this->organization('owner-boundary');
        [$owner, $ownerMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $access = app(ManagementDesignAccess::class);

        $this->assertTrue($access->canManage($owner, $organization));
        $this->assertFalse($access->canView($owner, $organization, ManagementDesignItem::TYPE_PHILOSOPHY));
        $this->assertFalse($access->canEdit($owner, $organization, ManagementDesignItem::TYPE_PHILOSOPHY));
        $this->asCompany($owner, $organization)->get(route('management-design.permissions'))->assertOk();
        $this->asCompany($owner, $organization)->get(route('management-design.show', 'philosophy'))->assertForbidden();
        $this->asCompany($owner, $organization)->get(route('management-design.edit', 'philosophy'))->assertForbidden();

        $this->permissions($owner, $organization, 'philosophy', 'explicit', [
            $ownerMembership->id => ['can_view' => true, 'can_edit' => true],
        ]);

        $this->assertTrue($access->canView($owner, $organization, 'philosophy'));
        $this->assertTrue($access->canEdit($owner, $organization, 'philosophy'));
    }

    public function test_all_active_staff_and_explicit_grants_reauthorize_current_membership(): void
    {
        $organization = $this->organization('current-membership');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$staff, $staffMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        [$editor, $editorMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $access = app(ManagementDesignAccess::class);

        $this->permissions($owner, $organization, 'vision', 'all_active_staff', [
            $editorMembership->id => ['can_view' => false, 'can_edit' => true],
        ]);
        $this->assertTrue($access->canView($staff, $organization, 'vision'));
        $this->assertFalse($access->canEdit($staff, $organization, 'vision'));
        $this->assertTrue($access->canEdit($editor, $organization, 'vision'));

        $staffMembership->update(['membership_status' => OrganizationUser::STATUS_SUSPENDED]);
        $this->assertFalse($access->canView($staff, $organization, 'vision'));
        $staffMembership->update(['membership_status' => OrganizationUser::STATUS_LEFT]);
        $this->assertFalse($access->canView($staff, $organization, 'vision'));

        $editor->update(['is_active' => false]);
        $this->assertFalse($access->canView($editor, $organization, 'vision'));
        $this->assertFalse($access->canEdit($editor, $organization, 'vision'));
    }

    public function test_explicit_editor_without_view_is_rejected_and_permission_update_is_idempotent(): void
    {
        $organization = $this->organization('explicit-contract');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $manager = app(ManagementDesignPermissionManager::class);

        try {
            $manager->update($owner, $organization, 'policy', 'explicit', [
                $membership->id => ['can_view' => false, 'can_edit' => true],
            ], (string) Str::uuid());
            $this->fail('Edit without View must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('grants', $exception->errors());
        }

        $requestId = (string) Str::uuid();
        $payload = [$membership->id => ['can_view' => true, 'can_edit' => true]];
        $first = $manager->update($owner, $organization, 'policy', 'explicit', $payload, $requestId);
        $second = $manager->update($owner, $organization, 'policy', 'explicit', $payload, $requestId);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('management_design_operations', 1);
        $this->assertDatabaseCount('management_design_grants', 1);
    }

    public function test_fixed_type_single_logical_item_zero_sections_and_long_japanese_are_supported(): void
    {
        $organization = $this->organization('fixed-type');
        [$owner, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->permissions($owner, $organization, 'philosophy', 'explicit', [
            $membership->id => ['can_view' => true, 'can_edit' => true],
        ]);
        $longJapanese = str_repeat('会社は人の可能性を信じ、社会へ誠実な価値を届けます。', 500);
        $writer = app(ManagementDesignWriter::class);
        $item = $writer->saveOfficial(
            $owner, $organization, 'philosophy', ['statement' => $longJapanese, 'sections' => []],
            0, null, (string) Str::uuid(),
        );

        $this->assertSame(1, $item->version);
        $this->assertSame($longJapanese, $item->statement);
        $this->assertCount(0, $item->sections);
        $this->assertNotNull($item->current_revision_id);
        $this->assertSame($item->id, $item->currentRevision->management_design_item_id);
        $this->assertDatabaseCount('management_design_items', 1);

        $this->expectException(QueryException::class);
        ManagementDesignItem::create([
            'organization_id' => $organization->id,
            'type' => 'philosophy',
            'status' => 'active',
            'version' => 0,
        ]);
    }

    public function test_many_sections_keep_order_stable_ids_and_immutable_revision_snapshots(): void
    {
        $organization = $this->organization('revision');
        [$owner, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->permissions($owner, $organization, 'vision', 'explicit', [
            $membership->id => ['can_view' => true, 'can_edit' => true],
        ]);
        $writer = app(ManagementDesignWriter::class);
        $first = $writer->saveOfficial($owner, $organization, 'vision', [
            'statement' => '地域から未来をつくる',
            'horizon' => '2035年を見据えて',
            'sections' => [
                ['title' => '顧客との未来', 'body' => '信頼が循環する関係をつくる', 'horizon' => '5〜10年'],
                ['title' => '働く人の未来', 'body' => '挑戦と安心が両立する会社にする', 'horizon' => null],
                ['title' => '地域の未来', 'body' => '次の世代へ価値を残す', 'horizon' => '長期'],
            ],
        ], 0, '最初の正本', (string) Str::uuid());
        $ids = $first->sections->pluck('public_id')->all();
        $revisionOne = $first->currentRevision;

        $second = $writer->saveOfficial($owner, $organization, 'vision', [
            'statement' => '地域から持続可能な未来をつくる',
            'horizon' => '2035年を見据えて',
            'sections' => [
                ['public_id' => $ids[2], 'title' => '地域の未来', 'body' => '次の世代へ価値を残し続ける', 'horizon' => '長期'],
                ['public_id' => $ids[0], 'title' => '顧客との未来', 'body' => '信頼が循環する関係をつくる', 'horizon' => '5〜10年'],
            ],
        ], 1, null, (string) Str::uuid());

        $this->assertSame(2, $second->version);
        $this->assertSame([$ids[2], $ids[0]], $second->sections->pluck('public_id')->all());
        $this->assertSame(['地域の未来', '顧客との未来'], $second->sections->pluck('title')->all());
        $this->assertCount(3, $revisionOne->snapshot['item']['sections']);
        $this->assertSame('地域から未来をつくる', $revisionOne->snapshot['item']['statement']);
        $this->assertDatabaseHas('management_design_sections', ['public_id' => $ids[1], 'status' => 'archived']);

        $this->expectException(LogicException::class);
        $revisionOne->update(['change_reason' => '書き換え不可']);
    }

    public function test_optional_explanations_are_versioned_without_backfilling_existing_content(): void
    {
        $organization = $this->organization('explanations');
        [$owner, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->permissions($owner, $organization, 'philosophy', 'explicit', [
            $membership->id => ['can_view' => true, 'can_edit' => true],
        ]);
        $writer = app(ManagementDesignWriter::class);
        $first = $writer->saveOfficial($owner, $organization, 'philosophy', [
            'statement' => '関わるすべての人に笑顔と信頼を届ける',
            'statement_explanation' => '会社がこのStatementに込めた固有の意味です。',
            'sections' => [[
                'title' => 'MISSION',
                'body' => '飲食店オーナーの夢を叶える',
                'explanation' => '事業を通じて果たす使命の説明です。',
            ]],
        ], 0, '説明を含む初回正本', (string) Str::uuid());
        $firstRevision = $first->currentRevision;
        $sectionId = $first->sections->first()->public_id;

        $this->assertSame('会社がこのStatementに込めた固有の意味です。', $first->statement_explanation);
        $this->assertSame('事業を通じて果たす使命の説明です。', $first->sections->first()->explanation);
        $this->assertSame(2, $firstRevision->snapshot_schema_version);
        $this->assertSame('会社がこのStatementに込めた固有の意味です。', $firstRevision->snapshot['item']['statement_explanation']);
        $this->assertSame('事業を通じて果たす使命の説明です。', $firstRevision->snapshot['item']['sections'][0]['explanation']);

        $second = $writer->saveOfficial($owner, $organization, 'philosophy', [
            'statement' => '関わるすべての人と共に成長する',
            'statement_explanation' => null,
            'sections' => [[
                'public_id' => $sectionId,
                'title' => 'MISSION',
                'body' => '地域の未来を創る',
                'explanation' => null,
            ]],
        ], 1, null, (string) Str::uuid());

        $this->assertNull($second->statement_explanation);
        $this->assertNull($second->sections->first()->explanation);
        $this->assertSame('会社がこのStatementに込めた固有の意味です。', $firstRevision->fresh()->snapshot['item']['statement_explanation']);
        $this->asCompany($owner, $organization)
            ->get(route('management-design.revisions.show', ['philosophy', 1]))
            ->assertOk()
            ->assertSee('会社がこのStatementに込めた固有の意味です。')
            ->assertSee('事業を通じて果たす使命の説明です。');
    }

    public function test_explanation_capability_is_optional_for_every_type_with_type_specific_presentation(): void
    {
        $organization = $this->organization('explanation-presentation');
        [$user, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        foreach (ManagementDesignItem::TYPES as $type) {
            $this->permissions($user, $organization, $type, 'explicit', [
                $membership->id => ['can_view' => true, 'can_edit' => true],
            ]);
        }

        $labels = [
            ManagementDesignItem::TYPE_PHILOSOPHY => ['この理念に込めた意味（任意）', 'このSectionの説明（任意）'],
            ManagementDesignItem::TYPE_VISION => ['このVisionが示す意味（任意）', 'このSectionが描く未来の説明（任意）'],
            ManagementDesignItem::TYPE_POLICY => ['この方針の背景・意図（任意）', 'このSectionの判断意図（任意）'],
        ];

        foreach ($labels as $type => [$statementLabel, $sectionLabel]) {
            $this->asCompany($user, $organization)
                ->get(route('management-design.edit', $type))
                ->assertOk()
                ->assertSee($statementLabel)
                ->assertSee($sectionLabel);

            app(ManagementDesignWriter::class)->saveOfficial(
                $user,
                $organization,
                $type,
                [
                    'statement' => $type.' statement',
                    'sections' => [['title' => 'Section', 'body' => 'Statement']],
                ],
                0,
                null,
                (string) Str::uuid(),
            );

            $item = ManagementDesignItem::query()
                ->where('organization_id', $organization->id)
                ->where('type', $type)
                ->with('sections')
                ->firstOrFail();
            $this->assertNull($item->statement_explanation);
            $this->assertNull($item->sections->first()->explanation);
        }
    }

    public function test_stale_foreign_section_and_reused_request_with_different_payload_fail_closed(): void
    {
        $organization = $this->organization('stale');
        [$owner, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->permissions($owner, $organization, 'policy', 'explicit', [
            $membership->id => ['can_view' => true, 'can_edit' => true],
        ]);
        $this->permissions($owner, $organization, 'vision', 'explicit', [
            $membership->id => ['can_view' => true, 'can_edit' => true],
        ]);
        $writer = app(ManagementDesignWriter::class);
        $policy = $writer->saveOfficial($owner, $organization, 'policy', [
            'statement' => '顧客価値を優先する', 'sections' => [['title' => '判断', 'body' => '長期価値で選ぶ']],
        ], 0, null, (string) Str::uuid());
        $vision = $writer->saveOfficial($owner, $organization, 'vision', [
            'statement' => '未来', 'sections' => [['title' => '未来', 'body' => '進む']],
        ], 0, null, (string) Str::uuid());

        try {
            $writer->saveOfficial($owner, $organization, 'policy', [
                'statement' => 'stale', 'sections' => [],
            ], 0, null, (string) Str::uuid());
            $this->fail('Stale save must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expected_version', $exception->errors());
        }

        try {
            $writer->saveOfficial($owner, $organization, 'policy', [
                'statement' => 'foreign',
                'sections' => [['public_id' => $vision->sections->first()->public_id, 'title' => 'x', 'body' => 'y']],
            ], $policy->version, null, (string) Str::uuid());
            $this->fail('Foreign section must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sections.0.public_id', $exception->errors());
        }

        $requestId = (string) Str::uuid();
        $updated = $writer->saveOfficial($owner, $organization, 'policy', [
            'statement' => '更新', 'sections' => [],
        ], 1, null, $requestId);
        $retried = $writer->saveOfficial($owner, $organization, 'policy', [
            'statement' => '更新', 'sections' => [],
        ], 1, null, $requestId);
        $this->assertSame($updated->version, $retried->version);
        $this->expectException(ValidationException::class);
        $writer->saveOfficial($owner, $organization, 'policy', ['statement' => '別内容', 'sections' => []], 1, null, $requestId);
    }

    public function test_transaction_rolls_back_current_revision_operation_and_audit_on_fault(): void
    {
        $organization = $this->organization('rollback');
        [$owner, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->permissions($owner, $organization, 'philosophy', 'explicit', [
            $membership->id => ['can_view' => true, 'can_edit' => true],
        ]);
        $writer = app(ManagementDesignWriter::class);
        $item = $writer->saveOfficial($owner, $organization, 'philosophy', ['statement' => '変更前', 'sections' => []], 0, null, (string) Str::uuid());
        $auditCount = OrganizationAuditEvent::count();

        $faulting = new class(app(ManagementDesignAccess::class), app(ManagementDesignSnapshot::class), app(OrganizationAudit::class)) extends ManagementDesignWriter
        {
            protected function checkpoint(string $name): void
            {
                if ($name === 'after_revision') {
                    throw new RuntimeException('synthetic rollback');
                }
            }
        };

        try {
            $faulting->saveOfficial($owner, $organization, 'philosophy', ['statement' => '変更後', 'sections' => []], 1, null, (string) Str::uuid());
            $this->fail('Synthetic fault must escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic rollback', $exception->getMessage());
        }

        $this->assertSame('変更前', $item->fresh()->statement);
        $this->assertSame(1, $item->fresh()->version);
        $this->assertDatabaseCount('management_design_revisions', 1);
        $this->assertDatabaseCount('management_design_operations', 2); // one permission + one completed save
        $this->assertSame($auditCount, OrganizationAuditEvent::count());
    }

    public function test_archive_and_reopen_keep_stable_id_content_and_history_continuity(): void
    {
        $organization = $this->organization('lifecycle');
        [$owner, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->permissions($owner, $organization, 'policy', 'explicit', [
            $membership->id => ['can_view' => true, 'can_edit' => true],
        ]);
        $writer = app(ManagementDesignWriter::class);
        $item = $writer->saveOfficial($owner, $organization, 'policy', [
            'statement' => '長期価値を優先する', 'sections' => [['title' => '投資判断', 'body' => '未来の価値で判断する']],
        ], 0, null, (string) Str::uuid());
        $stableId = $item->public_id;
        $sectionId = $item->sections->first()->public_id;
        $archived = $writer->archive($owner, $organization, 'policy', 1, '一時保管', (string) Str::uuid());
        $reopened = $writer->reopen($owner, $organization, 'policy', 2, null, (string) Str::uuid());

        $this->assertSame($stableId, $reopened->public_id);
        $this->assertSame($sectionId, $reopened->sections->first()->public_id);
        $this->assertSame('active', $reopened->status);
        $this->assertNull($reopened->archived_at);
        $this->assertSame(3, $reopened->version);
        $this->assertSame(['active', 'archived', 'active'], $reopened->revisions()->oldest('revision_no')->pluck('status')->all());
        $this->assertSame('archived', $archived->status);
    }

    public function test_tenant_history_and_route_boundaries_do_not_expose_content(): void
    {
        $first = $this->organization('tenant-a');
        [$owner, $ownerMembership] = $this->member($first, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->permissions($owner, $first, 'philosophy', 'explicit', [
            $ownerMembership->id => ['can_view' => true, 'can_edit' => true],
        ]);
        $item = app(ManagementDesignWriter::class)->saveOfficial($owner, $first, 'philosophy', [
            'statement' => '他社へ漏らさない理念',
            'statement_explanation' => '他社へ漏らさない理念説明',
            'sections' => [['title' => '秘密', 'body' => '本文', 'explanation' => '他社へ漏らさないSection説明']],
        ], 0, '監査へ本文を複製しない', (string) Str::uuid());

        $second = $this->organization('tenant-b');
        [$other, $otherMembership] = $this->member($second, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->permissions($other, $second, 'philosophy', 'explicit', [
            $otherMembership->id => ['can_view' => true, 'can_edit' => true],
        ]);
        $this->asCompany($other, $second)->get(route('management-design.show', 'philosophy'))
            ->assertOk()->assertDontSee('他社へ漏らさない理念');
        $this->asCompany($other, $second)->get(route('management-design.history', 'philosophy'))->assertNotFound();

        $auditJson = OrganizationAuditEvent::query()
            ->where('organization_id', $first->id)
            ->get()
            ->toJson(JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('他社へ漏らさない理念', $auditJson);
        $this->assertStringNotContainsString('他社へ漏らさない理念説明', $auditJson);
        $this->assertStringNotContainsString('他社へ漏らさないSection説明', $auditJson);
        $this->assertStringNotContainsString('監査へ本文を複製しない', $auditJson);
        $this->assertSame($item->id, ManagementDesignRevision::first()->management_design_item_id);
    }

    public function test_view_edit_history_and_three_experience_compositions_are_separate_and_view_first(): void
    {
        $organization = $this->organization('experience');
        [$owner, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        foreach (ManagementDesignItem::TYPES as $type) {
            $this->permissions($owner, $organization, $type, 'explicit', [
                $membership->id => ['can_view' => true, 'can_edit' => true],
            ]);
            app(ManagementDesignWriter::class)->saveOfficial($owner, $organization, $type, [
                'statement' => str_repeat('会社の言葉を丁寧に読み、判断へつなげる。', 40),
                'horizon' => $type === 'vision' ? '2035年を見据えて' : null,
                'sections' => [['title' => '大切にすること', 'body' => str_repeat('本文です。', 200), 'horizon' => $type === 'vision' ? '長期' : null]],
            ], 0, null, (string) Str::uuid());
        }

        $this->asCompany($owner, $organization)->get(route('company.home'))
            ->assertOk()->assertSee('理念・Vision・方針')->assertSee(route('management-design.index'));
        $this->asCompany($owner, $organization)->get(route('management-design.index'))
            ->assertOk()->assertSee('mdc-directory__item--philosophy', false)
            ->assertSee('mdc-directory__item--vision', false)->assertSee('mdc-directory__item--policy', false);

        foreach (['philosophy', 'vision', 'policy'] as $type) {
            $response = $this->asCompany($owner, $organization)->get(route('management-design.show', $type));
            $response->assertOk()->assertSee('mdc-read--'.$type, false)->assertSee('内容を編集')->assertSee('History');
            $this->assertStringNotContainsString('<textarea', $response->getContent());
            $this->asCompany($owner, $organization)->get(route('management-design.edit', $type))
                ->assertOk()->assertSee('正本として保存')->assertSee('Sectionを追加');
            $this->asCompany($owner, $organization)->get(route('management-design.history', $type))
                ->assertOk()->assertSee('Revision 1');
        }
    }

    public function test_non_vision_horizon_is_rejected_without_partial_write(): void
    {
        $organization = $this->organization('horizon');
        [$owner, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->permissions($owner, $organization, 'policy', 'explicit', [
            $membership->id => ['can_view' => true, 'can_edit' => true],
        ]);

        $this->asCompany($owner, $organization)->put(route('management-design.update', 'policy'), [
            'request_id' => (string) Str::uuid(),
            'expected_version' => 0,
            'statement' => '方針',
            'horizon' => '期限ではないがPolicyでは不可',
            'sections' => [],
        ])->assertSessionHasErrors('horizon');
        $this->assertDatabaseCount('management_design_items', 0);
        $this->assertDatabaseCount('management_design_revisions', 0);
    }

    private function permissions(
        User $owner,
        Organization $organization,
        string $type,
        string $scope,
        array $grants,
    ): void {
        app(ManagementDesignPermissionManager::class)->update(
            $owner, $organization, $type, $scope, $grants, (string) Str::uuid(),
        );
    }

    private function organization(string $slug): Organization
    {
        return Organization::create(['name' => 'Organization '.$slug, 'slug' => $slug.'-'.strtolower((string) Str::ulid())]);
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
        return $this->actingAs($user)->withSession([
            'access_mode' => 'workspace',
            'current_company_id' => $organization->id,
            'current_company_access_epoch' => 1,
            'credential_generation' => 1,
        ]);
    }
}
