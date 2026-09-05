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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    public function test_project_member_can_paste_screenshot_into_ai_chat(): void
    {
        Storage::fake('local');
        [$user, $workspace, $project] = $this->projectUser();
        WorkspaceAiSetting::create(['workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'member_managed_ai']);
        config(['services.openai.api_key' => 'test-key', 'services.openai.chat_model' => 'gpt-5.6-terra']);
        $thread = $project->aiChatThreads()->create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);
        foreach (range(1, 21) as $index) {
            $thread->messages()->create([
                'role' => $index % 2 ? 'user' : 'assistant',
                'content' => "過去の会話{$index}",
                'created_at' => now()->subMinutes(30 - $index),
                'updated_at' => now()->subMinutes(30 - $index),
            ]);
        }
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_image',
            'model' => 'gpt-5.6-terra',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '画像を確認しました。']]]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
        ])]);

        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-chat.messages.store', $project), [
                'content' => 'この画面を見て',
                'image' => UploadedFile::fake()->image('screen.png', 800, 600),
            ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('message.content', '画像を確認しました。');
        $message = $thread->messages()->reorder()->latest()->where('role', 'user')->firstOrFail();
        Storage::disk('local')->assertExists($message->image_path);
        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.ai-chat.messages.image', [$project, $message]))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
        Http::assertSent(function (Request $request): bool {
            $payload = json_encode($request['input'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return str_contains($payload, 'data:image/png;base64,')
                && str_contains($payload, 'この画面を見て');
        });
    }

    public function test_workspace_loads_the_latest_fifty_chat_messages_in_chronological_order(): void
    {
        [$user, $workspace, $project] = $this->projectUser();
        WorkspaceAiSetting::create(['workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'member_managed_ai']);
        config(['services.openai.api_key' => 'test-key']);
        $thread = $project->aiChatThreads()->create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);
        foreach (range(1, 55) as $index) {
            $thread->messages()->create([
                'role' => 'user',
                'content' => "会話番号{$index}",
                'created_at' => now()->addSeconds($index),
                'updated_at' => now()->addSeconds($index),
            ]);
        }

        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.workspace', $project));

        $response->assertOk()
            ->assertDontSee('会話番号1</div>', false)
            ->assertSeeInOrder(['会話番号6', '会話番号55']);
    }

    public function test_ai_can_return_a_pending_single_file_change_for_human_approval(): void
    {
        [$user, $workspace, $project] = $this->projectUser();
        WorkspaceAiSetting::create(['workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'member_managed_ai']);
        config(['services.openai.api_key' => 'test-key', 'services.openai.chat_model' => 'gpt-5.6-terra']);
        $original = "<?php\r\n echo 'before';\r\n";
        $updated = "<?php\n echo 'after';\n";
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_change',
            'model' => 'gpt-5.6-terra',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'answer' => '文言を変更する案を作成しました。',
                        'file_change' => ['path' => 'public_html/index.php', 'content' => $updated],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
            ]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
        ])]);

        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->postJson(route('projects.ai-chat.messages.store', $project), [
                'content' => '文言を直して',
                'context_key' => 'file:public_html/index.php',
                'file_path' => 'public_html/index.php',
                'file_content' => $original,
            ]);

        $response->assertOk()
            ->assertJsonPath('message.content', '文言を変更する案を作成しました。')
            ->assertJsonPath('message.file_change.path', 'public_html/index.php')
            ->assertJsonPath('message.file_change.content', $updated)
            ->assertJsonPath('message.file_change.original_hash', hash('sha256', str_replace("\r\n", "\n", $original)))
            ->assertJsonPath('message.file_change.status', 'pending');
        $message = $project->aiChatThreads()->firstOrFail()->messages()->reorder()->latest('id')->firstOrFail();
        $this->assertSame('pending', $message->file_change_status);
        Http::assertSent(fn (Request $request): bool =>
            data_get($request['text'], 'format.type') === 'json_schema'
            && data_get($request['text'], 'format.strict') === true
            && data_get($request['text'], 'format.name') === 'file_change_proposal'
        );

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-chat.messages.file-change.applied', [$project, $message]), [], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('status', 'applied');
        $this->assertDatabaseHas('ai_chat_messages', ['id' => $message->id, 'file_change_status' => 'applied']);
    }

    public function test_backup_restore_request_opens_local_change_history_without_calling_openai(): void
    {
        [$user, $workspace, $project] = $this->projectUser();
        WorkspaceAiSetting::create(['workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'member_managed_ai']);
        Http::fake();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->postJson(route('projects.ai-chat.messages.store', $project), [
                'content' => 'バックアップファイルから元に戻そう。',
                'context_key' => 'project',
            ])
            ->assertOk()
            ->assertJsonPath('ui_action', 'open_change_history')
            ->assertJsonPath('message.content', '変更履歴を開きました。戻したい日時の「差分を見る」で内容を確認し、「元に戻す」を押してください。復元する直前の状態も自動でバックアップされます。');

        Http::assertNothingSent();
        $this->assertDatabaseHas('ai_chat_messages', [
            'role' => 'user',
            'content' => 'バックアップファイルから元に戻そう。',
        ]);
    }

    public function test_ai_can_find_and_propose_a_change_to_a_file_that_is_not_open(): void
    {
        [$user, $workspace, $project] = $this->projectUser();
        WorkspaceAiSetting::create(['workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'member_managed_ai']);
        config(['services.openai.api_key' => 'test-key', 'services.openai.chat_model' => 'gpt-5.6-terra']);
        $original = json_encode(['title' => '厨房だけではなく、繁盛店を創る。'], JSON_UNESCAPED_UNICODE);
        $updated = json_encode(['title' => '厨房だけではなく 繁盛店を創る。'], JSON_UNESCAPED_UNICODE);
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_project_file',
            'model' => 'gpt-5.6-terra',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'answer' => '参照先のHEROデータに変更案を作成しました。',
                        'file_change' => ['path' => 'storage/content/hero.json', 'content' => $updated],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
            ]],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 30],
        ])]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->postJson(route('projects.ai-chat.messages.store', $project), [
                'content' => '見出しの読点を削除して',
                'context_key' => 'project',
                'project_files' => json_encode([
                    ['path' => 'public_html/index.php', 'content' => "<?php load_content('hero');"],
                    ['path' => 'deploy/oxserver-demo/storage-demo/content/hero.json', 'content' => $original],
                    ['path' => 'storage/content/hero.json', 'content' => $original],
                ], JSON_UNESCAPED_UNICODE),
            ])
            ->assertOk()
            ->assertJsonPath('message.file_change.path', 'storage/content/hero.json')
            ->assertJsonPath('message.file_change.content', $updated)
            ->assertJsonPath('message.file_change.original_hash', hash('sha256', $original));

        Http::assertSent(fn (Request $request): bool =>
            str_contains($request['instructions'], 'storage/content/hero.json')
            && ! str_contains($request['instructions'], 'deploy/oxserver-demo')
        );
    }

    public function test_owner_can_reject_a_pending_file_change(): void
    {
        [$user, $workspace, $project] = $this->projectUser();
        $thread = $project->aiChatThreads()->create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);
        $message = $thread->messages()->create([
            'role' => 'assistant',
            'content' => '変更案です。',
            'file_change_path' => 'public_html/index.php',
            'file_change_content' => '<h1>変更後</h1>',
            'file_change_original_hash' => hash('sha256', '<h1>変更前</h1>'),
            'file_change_status' => 'pending',
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.workspace', $project))
            ->assertOk()
            ->assertSee('data-viewer-panel="diff"', false)
            ->assertSee('.diff-actions[hidden]', false)
            ->assertSee('差分を確認')
            ->assertSee('AIへ修正を依頼')
            ->assertSee('提案を破棄');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-chat.messages.file-change.rejected', [$project, $message]), [], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('already_rejected', false);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-chat.messages.file-change.rejected', [$project, $message]), [], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('already_rejected', true);

        $this->assertDatabaseHas('ai_chat_messages', ['id' => $message->id, 'file_change_status' => 'rejected']);
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
            ->assertSee('AI：会話・画像生成に対応')
            ->assertSee('保存済みの会話です。')
            ->assertSee('data-chat-form', false)
            ->assertSee("payload.set('content', content)", false)
            ->assertSee('data-chat-image-input', false)
            ->assertSee('貼り付けもできます')
            ->assertSee('data-chat-file-content', false)
            ->assertSee('data-file-change-apply', false)
            ->assertSee('利用料をチェックする')
            ->assertSee('AI利用ポイント')
            ->assertSee('1ポイント')
            ->assertSee('変更履歴')
            ->assertSee('.rise-gate/backups/')
            ->assertSee('data-change-history', false)
            ->assertSee('data-add-backup-gitignore', false)
            ->assertSee('date.getFullYear()', false)
            ->assertSee('更新日時：', false)
            ->assertSee('file-preview-title__time', false)
            ->assertSee('revealLocalFile', false)
            ->assertSee("button.dataset.explorerTab === 'files'", false)
            ->assertSee('requestTerms', false)
            ->assertSee('通信を確認して、もう一度', false)
            ->assertDontSee('推定利用料')
            ->assertDontSee('$0.0006');
    }

    public function test_chat_generates_private_image_and_reuses_it_for_follow_up(): void
    {
        Storage::fake('local');
        [$user, $workspace, $project] = $this->projectUser();
        WorkspaceAiSetting::create(['workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'member_managed_ai']);
        config(['services.openai.api_key' => 'test-key']);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-05 12:00:00', 'Asia/Tokyo'));
        $fixture = UploadedFile::fake()->image('generated.png', 32, 32);
        $png = file_get_contents($fixture->getPathname());
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push([
                'id' => 'resp_generated',
                'output' => [['type' => 'image_generation_call', 'status' => 'completed', 'result' => base64_encode($png)]],
            ])
            ->push(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '背景を確認しました。']]]]])
        ]);

        $response = $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->postJson(route('projects.ai-chat.messages.store', $project), [
                'content' => 'この案のパッケージ画像を作って',
                'generate_image' => true,
                'file_path' => 'サイトSNS.txt',
                'file_content' => '墨黒のマット箱に金の文字',
            ]);
        $response->assertOk()->assertJsonPath('message.content', '画像を生成しました。')
            ->assertJsonPath('message.created_at', '2026-09-05T12:00:00+09:00');
        $message = $project->aiChatThreads()->firstOrFail()->messages()->where('role', 'assistant')->firstOrFail();
        $this->assertSame($png, Storage::disk('local')->get($message->image_path));
        $this->assertStringContainsString('/generated/', $message->image_path);
        $this->assertSame('image/png', $message->image_mime);
        $this->get($response->json('message.image_url'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(route('projects.workspace', $project))->assertOk()
            ->assertSee($message->image_name)
            ->assertSee('画像をダウンロード')
            ->assertSee('画像を生成する')
            ->assertSee('message.image_url', false);

        $this->postJson(route('projects.ai-chat.messages.store', $project), ['content' => 'この画像の背景を教えて'])
            ->assertOk()->assertJsonPath('message.content', '背景を確認しました。');
        Http::assertSent(fn (Request $request): bool =>
            data_get($request['tools'], '0.type') === 'image_generation'
            && data_get($request->data(), 'tool_choice.type') === 'image_generation'
            && str_contains($request['instructions'], '墨黒のマット箱に金の文字')
        );
        Http::assertSent(fn (Request $request): bool =>
            str_contains(json_encode($request['input'], JSON_UNESCAPED_SLASHES), 'data:image/png;base64,'.base64_encode($png))
            && ! isset($request['tool_choice'])
        );

        $message->thread->update(['user_id' => User::factory()->create()->id]);
        $this->get($response->json('message.image_url'))->assertNotFound();
        $this->travelBack();
    }

    public function test_invalid_generated_image_returns_error_without_saving_success(): void
    {
        Storage::fake('local');
        [$user, $workspace, $project] = $this->projectUser();
        WorkspaceAiSetting::create(['workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'member_managed_ai']);
        config(['services.openai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'output' => [['type' => 'image_generation_call', 'status' => 'completed', 'result' => base64_encode('invalid image')]],
        ])]);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->postJson(route('projects.ai-chat.messages.store', $project), ['content' => '画像を生成して', 'generate_image' => true])
            ->assertStatus(502)->assertJsonPath('message', '生成された画像を読み取れませんでした。もう一度お試しください。');
        $this->assertDatabaseMissing('ai_chat_messages', ['role' => 'assistant']);
        $this->assertDatabaseHas('ai_audit_logs', ['event' => 'ai_chat.failed', 'succeeded' => false]);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_generated_image_can_be_saved_by_button_or_chat_and_recorded_in_jst(): void
    {
        Storage::fake('local');
        [$user, $workspace, $project] = $this->projectUser();
        WorkspaceAiSetting::create(['workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'member_managed_ai']);
        config(['services.openai.api_key' => 'test-key']);
        $thread = $project->aiChatThreads()->create([
            'organization_id' => $project->organization_id, 'workspace_id' => $workspace->id, 'user_id' => $user->id,
        ]);
        $source = $thread->messages()->create([
            'role' => 'assistant', 'content' => '第1案です。', 'image_path' => "ai-chat/{$thread->id}/generated/test.png",
            'image_name' => 'test.png', 'image_mime' => 'image/png',
        ]);
        Storage::disk('local')->put($source->image_path, 'stored-image');
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id]);
        $this->get(route('projects.workspace', $project))->assertOk()
            ->assertSee('data-direct-image-save', false)->assertSee('フォルダへ保存');

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-05 19:00:00', 'Asia/Tokyo'));
        $this->postJson(route('projects.ai-chat.messages.image-saved', [$project, $source]), ['path' => 'デザイン案/第1案.png'])
            ->assertOk()->assertJsonPath('saved_at', '2026-09-05T19:00:00+09:00');
        $this->assertSame('saved', $source->fresh()->image_save['status']);

        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'output' => [[
                'type' => 'function_call', 'name' => 'save_generated_image',
                'arguments' => json_encode(['source_message_id' => $source->id, 'path' => 'クライアントA/画像/第1案.png'], JSON_UNESCAPED_UNICODE),
            ]],
        ])]);
        $response = $this->postJson(route('projects.ai-chat.messages.store', $project), [
            'content' => 'フォルダを作って、この画像を第1案として保存しておいて',
        ]);
        $response->assertOk()->assertJsonPath('message.image_save.path', 'クライアントA/画像/第1案.png')
            ->assertJsonPath('message.image_save.source_message_id', $source->id)
            ->assertJsonPath('message.image_save.status', 'pending')
            ->assertJsonPath('message.image_save.image_url', route('projects.ai-chat.messages.image', [$project, $source]));
        $this->assertSame(1, $thread->messages()->whereNotNull('image_path')->count());
        Http::assertSent(fn (Request $request): bool =>
            data_get($request['tools'], '1.name') === 'save_generated_image'
            && str_contains($request['instructions'], '"message_id":'.$source->id)
        );
        $saveUrl = $response->json('message.image_save.saved_url');
        $this->postJson($saveUrl, ['path' => '../outside.png'])->assertStatus(422);
        $this->postJson($saveUrl, ['path' => 'クライアントA/画像/第1案.png'])->assertOk()->assertJsonPath('status', 'saved');
        $this->get(route('projects.workspace', $project))->assertOk()->assertSee('data-image-save-history', false);
        $thread->update(['user_id' => User::factory()->create()->id]);
        $this->postJson($saveUrl, ['path' => '画像/第1案.png'])->assertNotFound();
        $this->travelBack();
    }

    public function test_ai_cannot_save_an_image_from_another_users_chat(): void
    {
        Storage::fake('local');
        [$user, $workspace, $project] = $this->projectUser();
        WorkspaceAiSetting::create(['workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'member_managed_ai']);
        config(['services.openai.api_key' => 'test-key']);
        $thread = $project->aiChatThreads()->create([
            'organization_id' => $project->organization_id, 'workspace_id' => $workspace->id, 'user_id' => User::factory()->create()->id,
        ]);
        $source = $thread->messages()->create(['role' => 'assistant', 'content' => '他ユーザーの画像', 'image_path' => 'private.png', 'image_mime' => 'image/png']);
        Storage::disk('local')->put('private.png', 'private-image');
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'output' => [['type' => 'function_call', 'name' => 'save_generated_image', 'arguments' => json_encode([
                'source_message_id' => $source->id, 'path' => '画像/第1案.png',
            ])]],
        ])]);
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->postJson(route('projects.ai-chat.messages.store', $project), ['content' => '画像を保存して'])
            ->assertStatus(502)->assertJsonPath('message', '保存する生成画像が見つかりません。この会話の画像を指定してください。');
        $this->assertDatabaseCount('ai_chat_messages', 2);
    }

    public function test_generated_image_uses_ai_filename_from_conversation_in_save_button_and_history(): void
    {
        Storage::fake('local');
        [$user, $workspace, $project] = $this->projectUser();
        WorkspaceAiSetting::create(['workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'member_managed_ai']);
        config(['services.openai.api_key' => 'test-key']);
        $thread = $project->aiChatThreads()->create([
            'organization_id' => $project->organization_id, 'workspace_id' => $workspace->id, 'user_id' => $user->id,
        ]);
        $thread->messages()->create(['role' => 'user', 'content' => 'お団子の中身が見えるパッケージにしたい']);
        $fixture = UploadedFile::fake()->image('fixture.png', 32, 32);
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'output' => [
                ['type' => 'image_generation_call', 'status' => 'completed', 'result' => base64_encode(file_get_contents($fixture->getPathname()))],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                    'answer' => '中身が見えるパターンを作りました。',
                    'image_name' => 'お団子中身見えるパターン.png', 'file_change' => null,
                ], JSON_UNESCAPED_UNICODE)]]],
            ],
        ])]);
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->postJson(route('projects.ai-chat.messages.store', $project), ['content' => 'それで生成して', 'generate_image' => true])
            ->assertOk()
            ->assertJsonPath('message.content', '中身が見えるパターンを作りました。')
            ->assertJsonPath('message.image_name', 'お団子中身見えるパターン.png')
            ->assertJsonPath('message.image_suggested_path', '画像/お団子中身見えるパターン.png')
            ->assertJsonPath('message.file_change', null);
        $message = $thread->messages()->where('role', 'assistant')->firstOrFail();
        $this->assertSame('お団子中身見えるパターン.png', $message->image_name);
        Storage::disk('local')->assertExists($message->image_path);
        $this->assertStringNotContainsString('お団子', $message->image_path);
        $this->get(route('projects.workspace', $project))->assertOk()
            ->assertSee('data-suggested-path="画像/お団子中身見えるパターン.png"', false)
            ->assertSee('Thinking')->assertSee('chat-thinking-spin');
        Http::assertSent(fn (Request $request): bool =>
            in_array('image_name', data_get($request['text'], 'format.schema.required'))
            && str_contains(json_encode($request['input'], JSON_UNESCAPED_UNICODE), 'お団子の中身が見える')
        );
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
