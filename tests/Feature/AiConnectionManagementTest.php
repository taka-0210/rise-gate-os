<?php

namespace Tests\Feature;

use App\Models\AiAccessKey;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiConnectionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_member_can_issue_personal_ai_connection_and_plain_token_is_not_stored(): void
    {
        [$user, $workspace] = $this->workspaceMember();

        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('ai-connections.store'), ['name' => 'My Codex', 'days' => 90]);

        $response->assertRedirect(route('ai-connections.index'))->assertSessionHas('new_ai_token');
        $plainToken = session('new_ai_token');
        $key = AiAccessKey::firstOrFail();
        $this->assertStringStartsWith('rgos_', $plainToken);
        $this->assertNotSame($plainToken, $key->token_hash);
        $this->assertSame(hash('sha256', $plainToken), $key->token_hash);
        $this->assertSame($user->id, $key->user_id);
        $this->assertSame($workspace->id, $key->workspace_id);
        $this->assertContains(AiAccessKey::SCOPE_PROJECTS_READ, $key->scopes);
        $this->assertContains(AiAccessKey::SCOPE_PROPOSALS_CREATE, $key->scopes);
        $this->assertDatabaseHas('ai_audit_logs', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'event' => 'connection.created',
            'succeeded' => true,
        ]);
    }

    public function test_member_only_sees_and_revokes_own_workspace_connections(): void
    {
        [$user, $workspace] = $this->workspaceMember();
        [$otherUser] = $this->workspaceMember('other');
        $ownKey = $this->key($workspace, $user, 'Own Codex');
        $otherKey = $this->key($workspace, $otherUser, 'Other Codex');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('ai-connections.index'))
            ->assertOk()
            ->assertSee('Own Codex')
            ->assertDontSee('Other Codex');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->delete(route('ai-connections.destroy', $otherKey))
            ->assertNotFound();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->delete(route('ai-connections.destroy', $ownKey))
            ->assertRedirect(route('ai-connections.index'));
        $this->assertNotNull($ownKey->fresh()->revoked_at);
        $this->assertNull($otherKey->fresh()->revoked_at);
        $this->assertDatabaseHas('ai_audit_logs', ['ai_access_key_id' => $ownKey->id, 'event' => 'connection.revoked']);
    }

    private function key(Workspace $workspace, User $user, string $name): AiAccessKey
    {
        return AiAccessKey::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => hash('sha256', $name.random_int(1, 999999)),
            'scopes' => [AiAccessKey::SCOPE_PROJECTS_READ],
            'expires_at' => now()->addDays(90),
        ]);
    }

    private function workspaceMember(string $suffix = 'main'): array
    {
        $user = User::factory()->create(['email' => $suffix.'@example.com']);
        $organization = Organization::create(['name' => 'Org '.$suffix, 'slug' => 'org-'.$suffix]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'owner_user_id' => $user->id,
            'name' => 'Workspace '.$suffix,
            'slug' => 'workspace-'.$suffix,
        ]);
        $organization->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $user->workspaces()->attach($workspace->id, ['role' => 'owner', 'joined_at' => now()]);
        WorkspaceAiSetting::create([
            'workspace_id' => $workspace->id,
            'enabled' => true,
            'provider' => 'member_managed_ai',
            'allowed_data_categories' => WorkspaceAiSetting::DEFAULT_DATA_CATEGORIES,
            'terms_version' => WorkspaceAiSetting::TERMS_VERSION,
            'enabled_by' => $user->id,
            'enabled_at' => now(),
        ]);
        return [$user, $workspace];
    }
}
