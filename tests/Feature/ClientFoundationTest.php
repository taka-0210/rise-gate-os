<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_client_in_current_workspace(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();

        $response = $this
            ->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post('/clients', [
                'name' => 'Sample Company',
                'kana' => '繧ｵ繝ｳ繝励Ν繧ｫ繝ｳ繝代ル繝ｼ',
                'email' => 'hello@example.com',
                'phone' => '090-0000-0000',
                'website' => 'https://example.com',
                'postal_code' => '100-0001',
                'address' => 'Tokyo',
                'memo' => 'Company level client record.',
            ]);

        $client = Client::firstOrFail();

        $response->assertRedirect(route('clients.show', $client));
        $this->assertSame($workspace->organization_id, $client->organization_id);
        $this->assertSame($workspace->id, $client->workspace_id);
        $this->assertSame('Sample Company', $client->name);
    }

    public function test_client_list_only_shows_current_workspace_clients(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        [, $otherWorkspace] = $this->createWorkspaceOwner('Other Org', 'Other Workspace', 'other@example.com');

        Client::create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'name' => 'Visible Company',
        ]);
        Client::create([
            'organization_id' => $otherWorkspace->organization_id,
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Hidden Company',
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get('/clients');

        $response->assertOk();
        $response->assertSee('Visible Company');
        $response->assertDontSee('Hidden Company');
    }

    public function test_user_cannot_view_client_from_other_workspace(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        [, $otherWorkspace] = $this->createWorkspaceOwner('Other Org', 'Other Workspace', 'other@example.com');

        $client = Client::create([
            'organization_id' => $otherWorkspace->organization_id,
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Private Company',
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('clients.show', $client));

        $response->assertNotFound();
    }

    public function test_workspace_viewer_cannot_create_client(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        $workspace->users()->updateExistingPivot($user->id, ['role' => 'viewer']);

        $createResponse = $this
            ->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get('/clients/create');

        $storeResponse = $this
            ->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post('/clients', [
                'name' => 'Viewer Company',
            ]);

        $createResponse->assertForbidden();
        $storeResponse->assertForbidden();
        $this->assertDatabaseMissing('clients', [
            'name' => 'Viewer Company',
        ]);
    }
    public function test_project_can_be_created_with_client(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        $client = Client::create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'name' => 'Project Parent Company',
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post('/projects', [
                'client_id' => $client->id,
                'name' => 'Client Project',
                'status' => 'active',
                'priority' => 'normal',
            ]);

        $project = Project::firstOrFail();

        $response->assertRedirect(route('projects.show', $project));
        $this->assertSame($client->id, $project->client_id);
    }

    public function test_project_requires_client(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();

        $response = $this
            ->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post('/projects', [
                'client_id' => null,
                'name' => 'Internal Improvement Project',
                'status' => 'active',
                'priority' => 'normal',
            ]);

        $response->assertSessionHasErrors('client_id');
        $this->assertDatabaseMissing('projects', ['name' => 'Internal Improvement Project']);
    }

    public function test_project_list_can_be_sorted_by_client_name(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        $zClient = Client::create(['organization_id' => $workspace->organization_id, 'workspace_id' => $workspace->id, 'name' => 'Zulu Client']);
        $aClient = Client::create(['organization_id' => $workspace->organization_id, 'workspace_id' => $workspace->id, 'name' => 'Alpha Client']);

        foreach ([[$zClient, 'Zulu Project'], [$aClient, 'Alpha Project']] as [$client, $name]) {
            $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])->post('/projects', [
                'client_id' => $client->id,
                'name' => $name,
                'status' => 'active',
                'priority' => 'normal',
            ]);
        }

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.index', ['sort' => 'client_asc']))
            ->assertOk()
            ->assertSeeInOrder(['Alpha Client', 'Zulu Client']);
    }

    public function test_project_list_can_be_filtered_by_client(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        $visibleClient = Client::create(['organization_id' => $workspace->organization_id, 'workspace_id' => $workspace->id, 'name' => 'Visible Client']);
        $hiddenClient = Client::create(['organization_id' => $workspace->organization_id, 'workspace_id' => $workspace->id, 'name' => 'Hidden Client']);
        Client::create(['organization_id' => $workspace->organization_id, 'workspace_id' => $workspace->id, 'name' => 'No Project Client']);

        foreach ([[$visibleClient, 'Visible Client Project'], [$hiddenClient, 'Hidden Client Project']] as [$client, $name]) {
            $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])->post('/projects', [
                'client_id' => $client->id,
                'name' => $name,
                'status' => 'active',
                'priority' => 'normal',
            ]);
        }

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.index', ['client_id' => $visibleClient->id]))
            ->assertOk()
            ->assertSee('Visible Client Project')
            ->assertDontSee('Hidden Client Project')
            ->assertDontSee('No Project Client');
    }

    public function test_project_list_can_filter_status_and_priority_and_sort_oldest(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        $client = Client::create(['organization_id' => $workspace->organization_id, 'workspace_id' => $workspace->id, 'name' => 'Filter Client']);

        foreach ([
            ['Old Matching Project', 'active', 'high'],
            ['New Matching Project', 'active', 'high'],
            ['Wrong Status Project', 'on_hold', 'high'],
            ['Wrong Priority Project', 'active', 'low'],
        ] as [$name, $status, $priority]) {
            $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])->post('/projects', [
                'client_id' => $client->id,
                'name' => $name,
                'status' => $status,
                'priority' => $priority,
            ]);
        }

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.index', ['status' => 'active', 'priority' => 'high', 'sort' => 'oldest']))
            ->assertOk()
            ->assertSeeInOrder(['Old Matching Project', 'New Matching Project'])
            ->assertDontSee('Wrong Status Project')
            ->assertDontSee('Wrong Priority Project');
    }

    public function test_project_cannot_use_client_from_other_workspace(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        [, $otherWorkspace] = $this->createWorkspaceOwner('Other Org', 'Other Workspace', 'other@example.com');
        $otherClient = Client::create([
            'organization_id' => $otherWorkspace->organization_id,
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Other Workspace Company',
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post('/projects', [
                'client_id' => $otherClient->id,
                'name' => 'Invalid Client Project',
                'status' => 'active',
                'priority' => 'normal',
            ]);

        $response->assertSessionHasErrors('client_id');
        $this->assertDatabaseMissing('projects', [
            'name' => 'Invalid Client Project',
        ]);
    }

    public function test_workspace_owner_can_delete_client_without_projects_using_password(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $client = Client::create(['organization_id' => $workspace->organization_id, 'workspace_id' => $workspace->id, 'name' => 'Delete Client']);

        $this->actingAs($owner)
            ->withSession(['access_mode' => 'workspace', 'current_workspace_id' => $workspace->id])
            ->delete(route('clients.destroy', $client), ['delete_password' => 'password'])
            ->assertRedirect(route('clients.index'));

        $this->assertSoftDeleted('clients', ['id' => $client->id]);
    }

    public function test_client_with_project_cannot_be_deleted(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $client = Client::create(['organization_id' => $workspace->organization_id, 'workspace_id' => $workspace->id, 'name' => 'Protected Client']);
        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])->post('/projects', [
            'client_id' => $client->id,
            'name' => 'Existing Client Project',
            'status' => 'active',
            'priority' => 'normal',
        ]);

        $this->actingAs($owner)
            ->withSession(['access_mode' => 'workspace', 'current_workspace_id' => $workspace->id])
            ->delete(route('clients.destroy', $client), ['delete_password' => 'password'])
            ->assertSessionHasErrors('client');

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'deleted_at' => null]);
    }

    public function test_client_is_not_deleted_with_wrong_password(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $client = Client::create(['organization_id' => $workspace->organization_id, 'workspace_id' => $workspace->id, 'name' => 'Wrong Password Client']);

        $this->actingAs($owner)
            ->withSession(['access_mode' => 'workspace', 'current_workspace_id' => $workspace->id])
            ->delete(route('clients.destroy', $client), ['delete_password' => 'wrong-password'])
            ->assertSessionHasErrors('delete_password');

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'deleted_at' => null]);
    }

    public function test_workspace_member_cannot_delete_client(): void
    {
        [$member, $workspace] = $this->createWorkspaceOwner();
        $workspace->users()->updateExistingPivot($member->id, ['role' => 'member']);
        $client = Client::create(['organization_id' => $workspace->organization_id, 'workspace_id' => $workspace->id, 'name' => 'Owner Protected Client']);

        $this->actingAs($member)
            ->withSession(['access_mode' => 'workspace', 'current_workspace_id' => $workspace->id])
            ->delete(route('clients.destroy', $client), ['delete_password' => 'password'])
            ->assertForbidden();

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'deleted_at' => null]);
    }

    protected function createWorkspaceOwner(
        string $organizationName = 'Rise Gate',
        string $workspaceName = 'Rise Gate Workspace',
        string $email = 'takami@example.com'
    ): array {
        $user = User::factory()->create(['email' => $email]);
        $organization = Organization::create([
            'name' => $organizationName,
            'slug' => str($organizationName)->slug().'-'.uniqid(),
        ]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'name' => $workspaceName,
            'slug' => str($workspaceName)->slug().'-'.uniqid(),
        ]);

        $organization->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);
        $workspace->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }
}

