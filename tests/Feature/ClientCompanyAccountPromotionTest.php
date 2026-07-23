<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientCompanyAccountPromotionTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_owner_can_promote_client_without_moving_existing_projects(): void
    {
        $user = User::factory()->create(['is_system_admin' => true]);
        $provider = Organization::create(['name' => 'Provider', 'slug' => 'provider']);
        $workspace = Workspace::create([
            'organization_id' => $provider->id,
            'owner_user_id' => $user->id,
            'name' => 'Client Workspace',
            'slug' => 'client-workspace',
            'status' => Workspace::STATUS_ACTIVE,
        ]);
        $provider->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $workspace->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $client = Client::create([
            'organization_id' => $provider->id,
            'workspace_id' => $workspace->id,
            'name' => '株式会社 ライズアップ',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['access_mode' => 'workspace', 'current_workspace_id' => $workspace->id])
            ->post(route('clients.company-account.store', $client), ['workspace_name' => '経営WS']);

        $company = Organization::query()->where('name', '株式会社 ライズアップ')->firstOrFail();
        $managementWorkspace = $company->workspaces()->where('name', '経営WS')->firstOrFail();

        $response->assertRedirect(route('company-finance.index'));
        $this->assertSame($company->id, $client->fresh()->linked_organization_id);
        $this->assertSame(Workspace::STATUS_ACTIVE, $managementWorkspace->status);
        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $company->id,
            'user_id' => $user->id,
            'role' => OrganizationUser::ROLE_OWNER,
            'company_role' => OrganizationUser::COMPANY_ROLE_OWNER,
        ]);
    }

    public function test_client_cannot_be_promoted_twice(): void
    {
        $user = User::factory()->create(['is_system_admin' => true]);
        $provider = Organization::create(['name' => 'Provider', 'slug' => 'provider']);
        $workspace = Workspace::create([
            'organization_id' => $provider->id,
            'owner_user_id' => $user->id,
            'name' => 'Client Workspace',
            'slug' => 'client-workspace',
            'status' => Workspace::STATUS_ACTIVE,
        ]);
        $provider->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $workspace->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $client = Client::create([
            'organization_id' => $provider->id,
            'workspace_id' => $workspace->id,
            'name' => 'Client Company',
        ]);
        $session = ['access_mode' => 'workspace', 'current_workspace_id' => $workspace->id];

        $this->actingAs($user)->withSession($session)
            ->post(route('clients.company-account.store', $client), ['workspace_name' => '経営WS'])
            ->assertRedirect(route('company-finance.index'));

        $this->actingAs($user)->withSession($session)
            ->post(route('clients.company-account.store', $client->fresh()), ['workspace_name' => '別WS'])
            ->assertSessionHasErrors('company_account');

        $this->assertSame(1, Organization::query()->where('name', 'Client Company')->count());
    }
}
