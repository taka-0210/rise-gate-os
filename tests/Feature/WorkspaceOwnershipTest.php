<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_owned_workspace_is_included_and_active(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'My Business', 'slug' => 'my-business']);
        $organization->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

        $response = $this->actingAs($user)
            ->withSession(['access_mode' => 'workspace', 'current_company_id' => $organization->id])
            ->post(route('workspaces.store'), [
                'workspace_name' => 'My Business WS',
                'type' => Workspace::TYPE_SHARED,
                'purpose' => '本業',
            ]);

        $workspace = Workspace::where('name', 'My Business WS')->firstOrFail();
        $response->assertRedirect(route('company.home'))->assertSessionHas('current_workspace_id', $workspace->id);
        $this->assertSame($user->id, $workspace->owner_user_id);
        $this->assertSame(Workspace::BILLING_INCLUDED, $workspace->billing_type);
        $this->assertSame(Workspace::STATUS_ACTIVE, $workspace->status);
        $this->assertSame('本業', $workspace->purpose);
    }

    public function test_second_owned_workspace_is_additional_and_pending(): void
    {
        $user = User::factory()->create();
        $firstWorkspace = $this->ownedWorkspace($user, 'First Workspace');

        $this->actingAs($user)
            ->withSession(['access_mode' => 'workspace', 'current_company_id' => $firstWorkspace->organization_id])
            ->post(route('workspaces.store'), [
                'workspace_name' => 'Second Workspace',
                'type' => Workspace::TYPE_SHARED,
            ])
            ->assertRedirect(route('workspaces.index'))
            ->assertSessionHas('status');

        $workspace = Workspace::where('name', 'Second Workspace')->firstOrFail();
        $this->assertSame(Workspace::BILLING_ADDITIONAL, $workspace->billing_type);
        $this->assertSame(Workspace::STATUS_PENDING, $workspace->status);
        $this->assertFalse($user->canAccessWorkspace($workspace->id));
    }

    public function test_invited_workspace_does_not_count_as_owned_workspace(): void
    {
        $user = User::factory()->create();
        $otherOwner = User::factory()->create();
        $invitedWorkspace = $this->ownedWorkspace($otherOwner, 'Invited Workspace');
        $invitedWorkspace->organization->users()->attach($user->id, ['role' => 'member', 'joined_at' => now()]);
        $invitedWorkspace->users()->attach($user->id, ['role' => 'member', 'joined_at' => now()]);

        $this->actingAs($user)
            ->withSession(['access_mode' => 'workspace', 'current_company_id' => $invitedWorkspace->organization_id])
            ->post(route('workspaces.store'), [
                'workspace_name' => 'My First Workspace',
                'type' => Workspace::TYPE_PERSONAL,
            ]);

        $workspace = Workspace::where('name', 'My First Workspace')->firstOrFail();
        $this->assertSame(Workspace::BILLING_INCLUDED, $workspace->billing_type);
        $this->assertSame(Workspace::STATUS_ACTIVE, $workspace->status);
        $this->assertSame(Workspace::TYPE_PERSONAL, $workspace->type);
    }

    public function test_pending_workspace_cannot_be_selected_until_system_admin_approves_it(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->ownedWorkspace($owner, 'Pending Workspace', Workspace::BILLING_ADDITIONAL, Workspace::STATUS_PENDING);

        $this->actingAs($owner)
            ->withSession(['access_mode' => 'workspace'])
            ->post(route('workspaces.switch', $workspace))
            ->assertForbidden();

        $admin = User::factory()->create(['is_system_admin' => true]);
        $this->actingAs($admin)
            ->withSession(['access_mode' => 'system_admin'])
            ->put(route('system-admin.workspaces.status.update', $workspace), ['status' => Workspace::STATUS_ACTIVE])
            ->assertRedirect(route('system-admin.workspaces.edit', $workspace));

        $this->actingAs($owner)
            ->withSession(['access_mode' => 'workspace'])
            ->post(route('workspaces.switch', $workspace))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('current_workspace_id', $workspace->id);
    }

    public function test_user_can_open_project_list_directly_for_selected_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->ownedWorkspace($owner, 'Project Workspace');

        $this->actingAs($owner)
            ->withSession(['access_mode' => 'workspace'])
            ->post(route('workspaces.projects', $workspace))
            ->assertRedirect(route('projects.index'))
            ->assertSessionHas('current_workspace_id', $workspace->id);
    }

    private function ownedWorkspace(
        User $owner,
        string $name,
        string $billingType = Workspace::BILLING_INCLUDED,
        string $status = Workspace::STATUS_ACTIVE
    ): Workspace {
        $organization = Organization::create(['name' => $name.' Org', 'slug' => str($name)->slug().'-'.uniqid()]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'owner_user_id' => $owner->id,
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'billing_type' => $billingType,
            'status' => $status,
        ]);
        $organization->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
        $workspace->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);

        return $workspace;
    }
}
