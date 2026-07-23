<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_company_user_enters_company_home_without_company_selector(): void
    {
        [$user, $company] = $this->companyUser('One Company');

        $this->actingAs($user)
            ->withSession(['access_mode' => 'workspace'])
            ->get(route('companies.index'))
            ->assertRedirect(route('company.home'))
            ->assertSessionHas('current_company_id', $company->id);

        $this->actingAs($user)
            ->withSession(['access_mode' => 'workspace', 'current_company_id' => $company->id])
            ->get(route('company.home'))
            ->assertOk()
            ->assertSee('One Company')
            ->assertDontSee('会社切替');
    }

    public function test_multiple_company_user_sees_company_selector_and_can_switch(): void
    {
        [$user, $first] = $this->companyUser('First Company');
        $second = Organization::create(['name' => 'Second Company', 'slug' => 'second-company']);
        $second->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($user)
            ->withSession(['access_mode' => 'workspace'])
            ->get(route('companies.index'))
            ->assertOk()
            ->assertSee('First Company')
            ->assertSee('Second Company');

        $this->actingAs($user)
            ->withSession([
                'access_mode' => 'workspace',
                'current_company_id' => $first->id,
                'current_workspace_id' => 999,
            ])
            ->post(route('companies.switch', $second))
            ->assertRedirect(route('company.home'))
            ->assertSessionHas('current_company_id', $second->id)
            ->assertSessionMissing('current_workspace_id');
    }

    public function test_personal_workspace_is_created_inside_current_company(): void
    {
        [$user, $company] = $this->companyUser('Personal Company', true);

        $this->actingAs($user)
            ->withSession([
                'access_mode' => 'workspace',
                'current_company_id' => $company->id,
            ])
            ->post(route('workspaces.store'), [
                'workspace_name' => '高見 個人WS',
                'type' => Workspace::TYPE_PERSONAL,
                'purpose' => '個人の仕事整理',
            ])
            ->assertRedirect(route('company.home'));

        $this->assertDatabaseHas('workspaces', [
            'organization_id' => $company->id,
            'name' => '高見 個人WS',
            'type' => Workspace::TYPE_PERSONAL,
            'personal_owner_user_id' => $user->id,
        ]);
    }

    private function companyUser(string $name, bool $systemAdmin = false): array
    {
        $user = User::factory()->create(['is_system_admin' => $systemAdmin]);
        $company = Organization::create(['name' => $name, 'slug' => str($name)->slug().'-'.uniqid()]);
        $company->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

        return [$user, $company];
    }
}
