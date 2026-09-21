<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\ProductOrganization\ProductOrganizationAdmission;
use App\Services\ProductOrganization\ProductOrganizationInventory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductOrganizationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['product_ux.organization_admission_enabled' => true]);
    }

    public function test_schema_is_additive_constrained_and_rollback_preserves_evidence(): void
    {
        $this->assertTrue(Schema::hasColumns('product_account_eligibilities', [
            'user_id', 'mode', 'product_organization_id', 'classification_version', 'classified_at', 'evidence_ref',
        ]));
        $this->assertTrue(Schema::hasColumns('product_organization_compatibilities', [
            'product_account_eligibility_id', 'organization_user_id', 'cutoff_at', 'evidence_ref',
        ]));
        $user = User::factory()->create();
        try {
            DB::table('product_account_eligibilities')->insert([
                'user_id' => $user->id,
                'mode' => ProductAccountEligibility::MODE_SINGLE,
                'product_organization_id' => null,
                'classification_version' => 'test',
                'classified_at' => now(),
                'evidence_ref' => 'invalid',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Cross-field constraint accepted an invalid single row.');
        } catch (QueryException) {
            $this->assertDatabaseCount('product_account_eligibilities', 0);
        }

        $migration = require database_path('migrations/2026_09_21_000008_add_product_organization_eligibility.php');
        $migration->down();
        $this->assertTrue(Schema::hasTable('product_account_eligibilities'));
        $this->assertTrue(Schema::hasTable('product_organization_compatibilities'));
    }

    public function test_inventory_dry_run_is_read_only_and_zero_membership_requires_review(): void
    {
        $user = User::factory()->create();
        $report = app(ProductOrganizationInventory::class)->dryRun('dry-run');

        $row = collect($report['rows'])->firstWhere('user_id', $user->id);
        $this->assertSame(ProductAccountEligibility::MODE_REVIEW_REQUIRED, $row['mode']);
        $this->assertDatabaseCount('product_account_eligibilities', 0);
        $this->assertSame(64, strlen($report['checksum']));
    }

    public function test_inventory_classifies_one_history_or_invited_membership_as_single(): void
    {
        foreach ([OrganizationUser::STATUS_INVITED, OrganizationUser::STATUS_SUSPENDED, OrganizationUser::STATUS_LEFT] as $status) {
            $user = User::factory()->create();
            $organization = $this->organization('single-'.$status);
            $this->membership($user, $organization, $status);
            $eligibility = app(ProductOrganizationInventory::class)->apply($user, 'history-'.$status);

            $this->assertSame(ProductAccountEligibility::MODE_SINGLE, $eligibility->mode);
            $this->assertSame($organization->id, $eligibility->product_organization_id);
        }
    }

    public function test_inventory_preserves_legacy_multi_with_explicit_membership_compatibilities(): void
    {
        $user = User::factory()->create();
        $first = $this->membership($user, $this->organization('multi-a'));
        $second = $this->membership($user, $this->organization('multi-b'), OrganizationUser::STATUS_SUSPENDED);
        $inventory = app(ProductOrganizationInventory::class);

        $eligibility = $inventory->apply($user, 'legacy-cutoff');
        $again = $inventory->apply($user, 'must-not-overwrite');

        $this->assertSame(ProductAccountEligibility::MODE_LEGACY_MULTI, $eligibility->mode);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            $eligibility->compatibilities()->pluck('organization_user_id')->all(),
        );
        $this->assertSame($eligibility->id, $again->id);
        $this->assertStringContainsString('legacy-cutoff', $again->evidence_ref);
    }

    public function test_inconsistent_workspace_footprint_is_review_required(): void
    {
        $user = User::factory()->create();
        $organization = $this->organization('orphan-workspace');
        $workspace = Workspace::query()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $user->id,
            'name' => 'Orphan',
            'slug' => 'orphan',
        ]);
        WorkspaceMember::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => WorkspaceMember::ROLE_OWNER,
            'joined_at' => now(),
        ]);

        $eligibility = app(ProductOrganizationInventory::class)->apply($user, 'inconsistent');
        $this->assertSame(ProductAccountEligibility::MODE_REVIEW_REQUIRED, $eligibility->mode);
    }

    public function test_first_successful_admission_binds_atomically_and_same_company_is_noop(): void
    {
        $user = User::factory()->create();
        $organization = $this->organization('first');
        $admission = app(ProductOrganizationAdmission::class);
        $admission->registerUnstarted($user, 'new-user');

        $result = $admission->admitExistingOrganization(
            $user,
            $organization,
            ProductOrganizationAdmission::ENTRY_SYSTEM_ADMIN_WORKSPACE,
            fn (): string => 'created',
        );
        $this->assertSame('created', $result);
        $this->assertDatabaseHas('product_account_eligibilities', [
            'user_id' => $user->id,
            'mode' => ProductAccountEligibility::MODE_SINGLE,
            'product_organization_id' => $organization->id,
        ]);

        $this->assertSame('same', $admission->admitExistingOrganization(
            $user,
            $organization,
            ProductOrganizationAdmission::ENTRY_STAFF_ACCEPT,
            fn (): string => 'same',
        ));
        $this->assertDatabaseCount('product_account_eligibilities', 1);
    }

    public function test_second_company_is_rejected_before_business_write_and_audited_privately(): void
    {
        $user = User::factory()->create();
        $first = $this->organization('bound');
        $second = $this->organization('secret-target-name');
        app(ProductOrganizationAdmission::class)->registerSingle($user, $first, 'bound');
        $called = false;

        try {
            app(ProductOrganizationAdmission::class)->admitExistingOrganization(
                $user,
                $second,
                ProductOrganizationAdmission::ENTRY_STAFF_ACCEPT,
                function () use (&$called): void {
                    $called = true;
                },
            );
            $this->fail('Second organization was not rejected.');
        } catch (ValidationException) {
            $this->assertFalse($called);
        }

        $event = DB::table('account_security_events')
            ->where('event', 'account.product_organization.admission_rejected')
            ->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertStringContainsString('second_organization', $event->metadata);
        $this->assertStringNotContainsString($second->name, $event->metadata);
        $this->assertSame('Asia/Tokyo', config('app.timezone'));
    }

    public function test_legacy_multi_allows_only_explicit_compatible_membership_for_same_user(): void
    {
        $user = User::factory()->create();
        $firstOrg = $this->organization('legacy-a');
        $secondOrg = $this->organization('legacy-b');
        $thirdOrg = $this->organization('legacy-c');
        $first = $this->membership($user, $firstOrg);
        $this->membership($user, $secondOrg);
        $eligibility = app(ProductOrganizationInventory::class)->apply($user, 'legacy');

        $this->assertSame('allowed', app(ProductOrganizationAdmission::class)->admitExistingOrganization(
            $user, $firstOrg, ProductOrganizationAdmission::ENTRY_STAFF_ACCEPT, fn () => 'allowed',
        ));
        $this->expectException(ValidationException::class);
        app(ProductOrganizationAdmission::class)->admitExistingOrganization(
            $user, $thirdOrg, ProductOrganizationAdmission::ENTRY_STAFF_ACCEPT, fn () => 'forbidden',
        );
    }

    private function organization(string $slug): Organization
    {
        return Organization::query()->create(['name' => $slug, 'slug' => $slug]);
    }

    private function membership(
        User $user,
        Organization $organization,
        string $status = OrganizationUser::STATUS_ACTIVE,
    ): OrganizationUser {
        return OrganizationUser::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => OrganizationUser::ROLE_MEMBER,
            'organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            'membership_status' => $status,
            'joined_at' => $status === OrganizationUser::STATUS_ACTIVE ? now() : null,
        ]);
    }
}
