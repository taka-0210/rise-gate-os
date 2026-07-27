<?php

namespace Tests\Feature;

use App\Models\AiAccessKey;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectHandoff;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_admin_can_save_latest_handoff_and_keep_history(): void
    {
        [$user, $workspace, $project] = $this->project();

        foreach ([
            ['completed_work' => 'ログインまで完了', 'next_work' => 'スタッフ一覧から再開'],
            ['completed_work' => 'スタッフ編集まで完了', 'next_work' => '本番公開から再開'],
        ] as $handoff) {
            $this->actingAs($user)
                ->withSession(['current_workspace_id' => $workspace->id])
                ->post(route('projects.handoffs.store', $project), $handoff)
                ->assertRedirect();
        }

        $this->assertDatabaseCount('project_handoffs', 2);
        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.handoffs.index', $project))
            ->assertOk()
            ->assertSee('スタッフ編集まで完了')
            ->assertSee('本番公開から再開')
            ->assertSee('ログインまで完了');
    }

    public function test_codex_handoff_proposal_requires_approval_before_becoming_latest(): void
    {
        [$user, $workspace, $project] = $this->project();
        $token = $this->accessKey($workspace, $user);

        $arguments = [
            'project_public_id' => $project->public_id,
            'idempotency_key' => 'handoff-test-001',
            'completed_work' => 'スタッフ基盤の管理画面まで完成',
            'next_work' => '本番環境へのデプロイ準備から再開',
        ];

        $this->withToken($token)->postJson('/api/mcp/rise-gate-os', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'submit_handoff_proposal', 'arguments' => $arguments],
        ])->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.status', ProjectHandoff::STATUS_PENDING);

        $handoff = ProjectHandoff::firstOrFail();
        $this->assertNull($project->handoffs()->where('status', ProjectHandoff::STATUS_APPROVED)->first());

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.handoffs.approve', [$project, $handoff]))
            ->assertRedirect();

        $handoff->refresh();
        $this->assertSame(ProjectHandoff::STATUS_APPROVED, $handoff->status);
        $this->assertSame($user->id, $handoff->reviewed_by);
        $this->assertNotNull($handoff->reviewed_at);

        $this->withToken($token)->postJson('/api/mcp/rise-gate-os', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'get_project_plan', 'arguments' => ['project_public_id' => $project->public_id]],
        ])->assertOk()
            ->assertJsonPath('result.structuredContent.handoff.completed_work', 'スタッフ基盤の管理画面まで完成')
            ->assertJsonPath('result.structuredContent.handoff.next_work', '本番環境へのデプロイ準備から再開');
    }

    public function test_view_only_member_cannot_update_or_approve_handoff(): void
    {
        [$owner, $workspace, $project] = $this->project();
        $viewer = User::factory()->create();
        $workspace->organization->users()->attach($viewer->id, ['role' => 'member', 'joined_at' => now()]);
        $workspace->users()->attach($viewer->id, ['role' => 'member', 'joined_at' => now()]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $viewer->id,
            'workspace_id' => $workspace->id,
            'project_role' => ProjectMember::ROLE_VIEWER,
            'permission_level' => ProjectMember::PERMISSION_VIEW,
            'invited_by' => $owner->id,
            'invited_at' => now(),
            'accepted_at' => now(),
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);
        $proposal = $project->handoffs()->create([
            'source' => ProjectHandoff::SOURCE_CODEX,
            'status' => ProjectHandoff::STATUS_PENDING,
            'completed_work' => '完了内容',
            'next_work' => '次の作業',
        ]);

        $this->actingAs($viewer)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.handoffs.store', $project), [
                'completed_work' => '不正な更新',
                'next_work' => '不正な次回作業',
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.handoffs.approve', [$project, $proposal]))
            ->assertForbidden();
    }

    private function project(): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Rise Gate', 'slug' => 'rise-gate-'.uniqid()]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'owner_user_id' => $user->id,
            'name' => 'Rise Gate',
            'slug' => 'rise-gate-'.uniqid(),
        ]);
        $organization->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $workspace->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $project = Project::create([
            'organization_id' => $organization->id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $user->id,
            'name' => '共通スタッフ基盤システム',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'project_role' => ProjectMember::ROLE_OWNER,
            'permission_level' => ProjectMember::PERMISSION_ADMIN,
            'invited_by' => $user->id,
            'invited_at' => now(),
            'accepted_at' => now(),
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);

        return [$user, $workspace, $project];
    }

    private function accessKey(Workspace $workspace, User $user): string
    {
        WorkspaceAiSetting::create([
            'workspace_id' => $workspace->id,
            'enabled' => true,
            'provider' => 'member_managed_ai',
            'allowed_data_categories' => WorkspaceAiSetting::DEFAULT_DATA_CATEGORIES,
            'terms_version' => WorkspaceAiSetting::TERMS_VERSION,
            'enabled_at' => now(),
        ]);
        $token = 'rgos_test_'.bin2hex(random_bytes(24));
        AiAccessKey::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'name' => 'Test Codex',
            'token_hash' => hash('sha256', $token),
            'scopes' => [AiAccessKey::SCOPE_PROJECTS_READ, AiAccessKey::SCOPE_PROPOSALS_CREATE],
            'expires_at' => now()->addHour(),
            'created_by' => $user->id,
        ]);

        return $token;
    }
}
