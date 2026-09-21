<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Company\PromoteClientToCompanyAccount;
use Database\Seeders\RiseGateOsOperationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ProductOrganizationOperationalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['product_ux.organization_admission_enabled' => true]);
    }

    public function test_inventory_command_is_dry_by_default_and_apply_is_idempotent(): void
    {
        $user = User::factory()->create();
        $organization = $this->organization('inventory-command');
        $membership = $this->membership($user, $organization);
        $before = [
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'membership_id' => $membership->id,
            'role' => $membership->organization_role,
        ];

        $this->assertSame(0, Artisan::call('product-organizations:inventory', [
            '--evidence' => 'test:inventory-dry',
        ]));
        $this->assertStringContainsString('"applied": false', Artisan::output());
        $this->assertDatabaseCount('product_account_eligibilities', 0);

        $this->assertSame(0, Artisan::call('product-organizations:inventory', [
            '--apply' => true,
            '--evidence' => 'test:inventory-apply',
        ]));
        $eligibilityId = ProductAccountEligibility::query()->where('user_id', $user->id)->sole()->id;
        $this->assertSame(0, Artisan::call('product-organizations:inventory', [
            '--apply' => true,
            '--evidence' => 'test:inventory-retry',
        ]));

        $this->assertSame($eligibilityId, ProductAccountEligibility::query()->where('user_id', $user->id)->sole()->id);
        $this->assertSame($before, [
            'user_id' => $user->fresh()->id,
            'organization_id' => $organization->fresh()->id,
            'membership_id' => $membership->fresh()->id,
            'role' => $membership->fresh()->organization_role,
        ]);
    }

    public function test_operation_seeder_is_fixture_only_and_creates_explicit_single_evidence_in_tests(): void
    {
        config(['app.env' => 'local', 'product_ux.operation_fixture_seeding_enabled' => false]);
        try {
            (new RiseGateOsOperationSeeder)->run();
            $this->fail('Fixture seeder ran without explicit authorization.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('fixture-only', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 0);

        config(['product_ux.operation_fixture_seeding_enabled' => true]);
        (new RiseGateOsOperationSeeder)->run();
        $user = User::query()->where('email', 'takami@rise-gate.local')->firstOrFail();
        $this->assertDatabaseHas('product_account_eligibilities', [
            'user_id' => $user->id,
            'mode' => ProductAccountEligibility::MODE_SINGLE,
        ]);
    }

    public function test_stale_single_company_post_is_rejected_and_safe_get_reselects_the_bound_company(): void
    {
        [$user, $organization] = $this->singleUser('stale-company');
        $stale = $this->organization('stale-other-company');
        $session = [
            'access_mode' => 'workspace',
            'credential_generation' => $user->credential_generation,
            'current_company_id' => $stale->id,
            'current_company_access_epoch' => 1,
        ];

        $this->actingAs($user)->withSession($session)
            ->post(route('business-domains.store'))
            ->assertStatus(409);
        $this->assertDatabaseCount('business_domains', 0);

        $this->get(route('company.home'))
            ->assertOk()
            ->assertSessionHas('current_company_id', $organization->id);
    }

    public function test_binding_survives_membership_and_identity_state_changes_without_releasing_a_second_company(): void
    {
        [$user, $organization, $membership] = $this->singleUser('binding-stays');
        $membership->update([
            'membership_status' => OrganizationUser::STATUS_SUSPENDED,
            'organization_role' => OrganizationUser::ORGANIZATION_ROLE_ADMIN,
            'access_epoch' => 2,
        ]);
        $user->update(['email' => 'changed@example.test']);

        $eligibility = $user->productAccountEligibility()->firstOrFail();
        $this->assertSame(ProductAccountEligibility::MODE_SINGLE, $eligibility->mode);
        $this->assertSame($organization->id, $eligibility->product_organization_id);

        $other = $this->organization('binding-stays-other');
        $this->actingAs($user)->withSession([
            'access_mode' => 'workspace',
            'credential_generation' => $user->credential_generation,
        ])->post(route('companies.switch', $other))->assertForbidden();
        $this->assertSame($organization->id, $user->productAccountEligibility()->firstOrFail()->product_organization_id);
    }

    public function test_existing_client_link_is_visible_and_never_rewritten_by_retired_promotion_service(): void
    {
        [$owner, $organization] = $this->singleUser('linked-provider');
        $workspace = $this->workspace($owner, $organization, 'linked-provider-workspace');
        $client = Client::query()->create([
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'linked_organization_id' => $organization->id,
            'name' => 'Existing Linked Client',
        ]);
        $session = [
            'access_mode' => 'workspace',
            'credential_generation' => $owner->credential_generation,
            'current_company_id' => $organization->id,
            'current_company_access_epoch' => 1,
            'current_workspace_id' => $workspace->id,
        ];

        $this->actingAs($owner)->withSession($session)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee($organization->name)
            ->assertDontSee(route('clients.company-account.store', $client));

        try {
            app(PromoteClientToCompanyAccount::class)->promote($client, $owner, 'Retired');
            $this->fail('Retired client promotion service created or rewrote a company.');
        } catch (ValidationException) {
            $this->assertSame($organization->id, $client->fresh()->linked_organization_id);
        }
    }

    private function singleUser(string $slug): array
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $organization = $this->organization($slug);
        $membership = $this->membership($user, $organization);
        ProductAccountEligibility::query()->create([
            'user_id' => $user->id,
            'mode' => ProductAccountEligibility::MODE_SINGLE,
            'product_organization_id' => $organization->id,
            'classification_version' => config('product_ux.classification_version'),
            'classified_at' => now(),
            'evidence_ref' => 'test:'.$slug,
        ]);

        return [$user, $organization, $membership];
    }

    private function organization(string $slug): Organization
    {
        return Organization::query()->create(['name' => $slug, 'slug' => $slug]);
    }

    private function membership(User $user, Organization $organization): OrganizationUser
    {
        return OrganizationUser::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => OrganizationUser::ROLE_MEMBER,
            'organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            'membership_status' => OrganizationUser::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
    }

    private function workspace(User $owner, Organization $organization, string $slug): Workspace
    {
        $workspace = Workspace::query()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $owner->id,
            'name' => $slug,
            'slug' => $slug,
            'status' => Workspace::STATUS_ACTIVE,
        ]);
        WorkspaceMember::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'role' => WorkspaceMember::ROLE_OWNER,
            'joined_at' => now(),
        ]);

        return $workspace;
    }
}
