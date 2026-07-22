<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_member_can_chat_with_read_only_ai_context(): void
    {
        [$user, $workspace, $project] = $this->projectUser();
        WorkspaceAiSetting::create([
            'workspace_id' => $workspace->id,
            'enabled' => true,
            'provider' => 'member_managed_ai',
            'allowed_data_categories' => WorkspaceAiSetting::DEFAULT_DATA_CATEGORIES,
        ]);
        config([
            'services.openai.api_key' => 'test-key',
            'services.openai.chat_model' => 'gpt-5.6-terra',
            'services.openai.input_usd_per_million' => 2.5,
            'services.openai.output_usd_per_million' => 15,
        ]);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_test_123',
                'model' => 'gpt-5.6-terra',
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => '現在地を確認しました。次の改善候補を整理できます。']],
                ]],
                'usage' => ['input_tokens' => 2000, 'output_tokens' => 400],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->postJson(route('projects.ai-chat.messages.store', $project), [
                'content' => '今の状況を教えて',
                'context_key' => 'project',
                'context_label' => $project->name.' / Project Overview',
            ]);

        $response->assertOk()
            ->assertJsonPath('message.role', 'assistant')
            ->assertJsonPath('message.content', '現在地を確認しました。次の改善候補を整理できます。')
            ->assertJsonPath('message.input_tokens', 2000)
            ->assertJsonPath('message.output_tokens', 400)
            ->assertJsonPath('message.estimated_cost_usd', 0.011);

        $this->assertDatabaseHas('ai_chat_messages', ['role' => 'user', 'content' => '今の状況を教えて']);
        $this->assertDatabaseHas('ai_chat_messages', [
            'role' => 'assistant',
            'provider_response_id' => 'resp_test_123',
            'estimated_cost_microusd' => 11000,
        ]);
        $this->assertDatabaseHas('ai_audit_logs', ['event' => 'ai_chat.responded', 'succeeded' => true]);

        Http::assertSent(function (Request $request) use ($project): bool {
            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request['model'] === 'gpt-5.6-terra'
                && $request['store'] === false
                && str_contains($request['instructions'], $project->name)
                && str_contains($request['instructions'], 'OSのデータを変更した、保存した、承認したとは決して述べない');
        });
    }

    public function test_disabled_workspace_cannot_use_project_chat(): void
    {
        [$user, $workspace, $project] = $this->projectUser();
        WorkspaceAiSetting::create(['workspace_id' => $workspace->id, 'enabled' => false, 'provider' => 'member_managed_ai']);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->postJson(route('projects.ai-chat.messages.store', $project), ['content' => '質問'])
            ->assertForbidden()
            ->assertJsonPath('message', 'このWorkspaceではAI機能が有効になっていません。');

        $this->assertDatabaseCount('ai_chat_messages', 0);
    }

    public function test_three_pane_workspace_shows_chat_history_and_tokens_on_demand(): void
    {
        [$user, $workspace, $project] = $this->projectUser();
        WorkspaceAiSetting::create(['workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'member_managed_ai']);
        config(['services.openai.api_key' => 'test-key']);
        $thread = $project->aiChatThreads()->create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);
        $thread->messages()->create([
            'role' => 'assistant',
            'content' => '保存済みの会話です。',
            'input_tokens' => 100,
            'output_tokens' => 20,
            'estimated_cost_microusd' => 550,
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.workspace', $project))
            ->assertOk()
            ->assertSee('読み取り専用AI：接続可能')
            ->assertSee('保存済みの会話です。')
            ->assertSee('data-chat-form', false)
            ->assertSee('利用料をチェックする')
            ->assertSee('利用トークン')
            ->assertSee('120 tokens')
            ->assertDontSee('推定利用料')
            ->assertDontSee('$0.0006');
    }

    private function projectUser(): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Chat Org', 'slug' => 'chat-org-'.uniqid()]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'owner_user_id' => $user->id,
            'name' => 'Chat Workspace',
            'slug' => 'chat-workspace-'.uniqid(),
            'status' => Workspace::STATUS_ACTIVE,
        ]);
        $organization->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $user->workspaces()->attach($workspace->id, ['role' => 'owner', 'joined_at' => now()]);
        $client = Client::create([
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'name' => 'Chat Client',
            'created_by' => $user->id,
        ]);
        $project = Project::create([
            'organization_id' => $organization->id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'owner_user_id' => $user->id,
            'name' => 'COMPANY OS構想',
            'current_state' => '3ペイン表示を試作済み',
            'desired_future_state' => 'AIと会社を育てるOS',
            'status' => Project::STATUS_ACTIVE,
            'priority' => Project::PRIORITY_HIGH,
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'project_role' => ProjectMember::ROLE_OWNER,
            'permission_level' => ProjectMember::PERMISSION_ADMIN,
            'status' => ProjectMember::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        return [$user, $workspace, $project];
    }
}
