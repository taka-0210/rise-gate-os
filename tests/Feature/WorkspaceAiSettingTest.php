<?php

namespace Tests\Feature;

use App\Models\AiAccessKey;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceAiSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_owner_can_enable_and_disable_ai_with_recorded_consent(): void
    {
        [$owner, $workspace] = $this->workspaceUser('owner');

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('ai-settings.update'), ['enabled' => '1', 'consent' => '1'])
            ->assertRedirect(route('ai-settings.edit'));

        $setting = $workspace->aiSetting()->firstOrFail();
        $this->assertTrue($setting->enabled);
        $this->assertSame($owner->id, $setting->enabled_by);
        $this->assertSame(WorkspaceAiSetting::TERMS_VERSION, $setting->terms_version);
        $this->assertDatabaseHas('ai_audit_logs', ['event' => 'workspace_ai.enabled', 'user_id' => $owner->id]);

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('ai-settings.update'), ['enabled' => '0'])
            ->assertRedirect(route('ai-settings.edit'));
        $this->assertFalse($setting->fresh()->enabled);
    }

    public function test_regular_member_cannot_change_workspace_ai_setting(): void
    {
        [$member, $workspace] = $this->workspaceUser('member');

        $this->actingAs($member)->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('ai-settings.update'), ['enabled' => '1', 'consent' => '1'])
            ->assertForbidden();
    }

    public function test_disabled_workspace_blocks_existing_ai_key(): void
    {
        [$owner, $workspace] = $this->workspaceUser('owner');
        WorkspaceAiSetting::create(['workspace_id' => $workspace->id, 'enabled' => false, 'provider' => 'member_managed_ai']);
        $token = 'rgos_disabled_test';
        AiAccessKey::create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'name' => 'Disabled key',
            'token_hash' => hash('sha256', $token),
            'scopes' => [AiAccessKey::SCOPE_PROJECTS_READ],
            'expires_at' => now()->addDay(),
        ]);

        $this->withToken($token)->getJson('/api/v1/ai/projects')
            ->assertForbidden()
            ->assertJsonPath('message', 'このWorkspaceではAI機能が有効化されていません。');
    }

    private function workspaceUser(string $role): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Consent Org '.$role, 'slug' => 'consent-org-'.$role]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'owner_user_id' => $user->id,
            'name' => 'Consent WS '.$role,
            'slug' => 'consent-ws-'.$role,
        ]);
        $organization->users()->attach($user->id, ['role' => $role === 'owner' ? 'owner' : 'member', 'joined_at' => now()]);
        $user->workspaces()->attach($workspace->id, ['role' => $role, 'joined_at' => now()]);
        return [$user, $workspace];
    }
}
