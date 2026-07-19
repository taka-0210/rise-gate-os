<?php

namespace Tests\Feature;

use App\Models\AiProposal;
use App\Models\AiProposalItem;
use App\Models\AiRequest;
use App\Models\Improvement;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectInternalNoteAttachment;
use App\Models\ProjectMember;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiProposalFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_member_can_send_private_attachment_with_ai_request_and_download_it(): void
    {
        Storage::fake('local');
        [$user, $workspace, $project] = $this->projectOwner('attachments');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-requests.store', $project), [
                'title' => '資料を読んで提案して',
                'instructions' => '現在の管理表をもとに計画してください。',
                'attachments' => [UploadedFile::fake()->image('current-board.jpg')],
            ])->assertRedirect();

        $aiRequest = AiRequest::with('attachments')->firstOrFail();
        $attachment = $aiRequest->attachments->firstOrFail();
        Storage::disk('local')->assertExists($attachment->stored_path);
        $this->assertDatabaseHas('ai_request_attachments', [
            'ai_request_id' => $aiRequest->id,
            'original_name' => 'current-board.jpg',
            'workspace_id' => $workspace->id,
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.ai-requests.attachments.download', [$project, $aiRequest, $attachment]))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_ai_request_returns_copy_text_for_codex(): void
    {
        [$user, $workspace, $project] = $this->projectOwner('copy-for-codex');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('projects.show', $project))
            ->post(route('projects.ai-requests.store', $project), [
                'title' => '見積機能の続きを進める',
                'instructions' => '登録済みの計画を確認して作業してください。',
            ])
            ->assertRedirect(route('projects.show', $project))
            ->assertSessionHas('ai_request_copy_text', function (string $text) use ($project): bool {
                return str_contains($text, "プロジェクト「{$project->name}」")
                    && str_contains($text, '見積機能の続きを進める')
                    && str_contains($text, '未処理のAI依頼を確認');
            });
    }

    public function test_selected_internal_note_and_its_file_are_snapshotted_into_ai_request(): void
    {
        Storage::fake('local');
        [$user, $workspace, $project] = $this->projectOwner('internal-note-ai');
        $note = $project->internalNotes()->create([
            'user_id' => $user->id,
            'body' => '給与計算の締日は毎月末日です。',
        ]);
        Storage::disk('local')->put('project-internal-notes/source/format.csv', "name,amount\nA,1000");
        ProjectInternalNoteAttachment::create([
            'project_internal_note_id' => $note->id,
            'project_id' => $project->id,
            'uploaded_by' => $user->id,
            'original_name' => '給与形式.csv',
            'stored_path' => 'project-internal-notes/source/format.csv',
            'mime_type' => 'text/csv',
            'extension' => 'csv',
            'size_bytes' => 18,
            'sha256' => hash('sha256', "name,amount\nA,1000"),
        ]);
        $note->references()->create([
            'project_id' => $project->id,
            'url' => 'https://example.com/reference-design',
            'title' => '参考デザイン',
            'reference_points' => 'ファーストビューの余白と導線',
            'avoid_points' => '配色と文章は模倣しない',
            'share_with_ai' => true,
        ]);
        $note->references()->create([
            'project_id' => $project->id,
            'url' => 'https://example.com/internal-only',
            'share_with_ai' => false,
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-requests.store', $project), [
                'title' => '社内資料を参照して計画して',
                'instructions' => 'ロードマップを提案してください。',
                'internal_note_ids' => [$note->id],
            ])->assertRedirect();

        $aiRequest = AiRequest::with('attachments')->firstOrFail();
        $this->assertStringContainsString('給与計算の締日は毎月末日です。', $aiRequest->instructions);
        $this->assertStringContainsString('給与形式.csv', $aiRequest->instructions);
        $this->assertStringContainsString('https://example.com/reference-design', $aiRequest->instructions);
        $this->assertStringContainsString('ファーストビューの余白と導線', $aiRequest->instructions);
        $this->assertStringContainsString('配色と文章は模倣しない', $aiRequest->instructions);
        $this->assertStringNotContainsString('https://example.com/internal-only', $aiRequest->instructions);
        $this->assertCount(1, $aiRequest->attachments);
        $snapshot = $aiRequest->attachments->first();
        $this->assertNotSame('project-internal-notes/source/format.csv', $snapshot->stored_path);
        Storage::disk('local')->assertExists($snapshot->stored_path);
    }

    public function test_internal_note_can_store_a_structured_web_reference(): void
    {
        [$user, $workspace, $project] = $this->projectOwner('web-reference');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.internal-notes.store', $project), [
                'reference_url' => 'https://example.com/inspiration',
                'reference_title' => '参考トップページ',
                'reference_points' => '余白とスクロール演出',
                'reference_avoid_points' => 'ロゴと文章は使わない',
                'reference_share_with_ai' => '1',
            ])->assertRedirect(route('projects.show', $project));

        $reference = $project->internalNotes()->firstOrFail()->references()->firstOrFail();
        $this->assertSame('https://example.com/inspiration', $reference->url);
        $this->assertSame('余白とスクロール演出', $reference->reference_points);
        $this->assertTrue($reference->share_with_ai);

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('class="internal-reference-fields"', false)
            ->assertSee('参考Webページを追加')
            ->assertSee('参考トップページ')
            ->assertSee('Codexへ共有');
    }

    public function test_internal_note_image_is_private_and_viewable_only_by_internal_members(): void
    {
        Storage::fake('local');
        [$owner, $workspace, $project] = $this->projectOwner('private-note-file');

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.internal-notes.store', $project), [
                'body' => '社内確認用の画像です。',
                'attachments' => [
                    UploadedFile::fake()->image('internal-board.jpg'),
                    UploadedFile::fake()->create('internal-guide.pdf', 10, 'application/pdf'),
                    UploadedFile::fake()->createWithContent('internal-list.csv', "name,amount\nA,1000"),
                    UploadedFile::fake()->create('internal-plan.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
                ],
            ])->assertRedirect();

        $note = $project->internalNotes()->firstOrFail();
        $attachment = $note->attachments()->where('extension', 'jpg')->firstOrFail();
        $pdf = $note->attachments()->where('extension', 'pdf')->firstOrFail();
        $csv = $note->attachments()->where('extension', 'csv')->firstOrFail();
        $excel = $note->attachments()->where('extension', 'xlsx')->firstOrFail();
        Storage::disk('local')->assertExists($attachment->stored_path);
        Storage::disk('local')->assertExists($pdf->stored_path);
        Storage::disk('local')->assertExists($csv->stored_path);
        Storage::disk('local')->assertExists($excel->stored_path);
        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('画像を閲覧')
            ->assertSee('PDFを閲覧')
            ->assertSee('CSVを閲覧')
            ->assertSee('Excelを閲覧');
        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.internal-notes.attachments.view', [$project, $note, $attachment]))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.internal-notes.attachments.view', [$project, $note, $pdf]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline');
        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.internal-notes.attachments.download', [$project, $note, $pdf]))
            ->assertOk()
            ->assertHeader('content-disposition');
        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.internal-notes.attachments.view', [$project, $note, $csv]))
            ->assertOk()
            ->assertHeader('content-disposition', 'inline');
        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.internal-notes.attachments.excel', [$project, $note, $excel]))
            ->assertOk()
            ->assertSee('internal-plan.xlsx')
            ->assertSee('xlsx.full.min.js');

        $client = User::factory()->create();
        $project->organization->users()->attach($client->id, ['role' => 'member', 'joined_at' => now()]);
        $workspace->users()->attach($client->id, ['role' => 'member', 'joined_at' => now()]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $client->id,
            'workspace_id' => $workspace->id,
            'project_role' => ProjectMember::ROLE_CLIENT,
            'permission_level' => ProjectMember::PERMISSION_VIEW,
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);
        $this->actingAs($client)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.internal-notes.attachments.view', [$project, $note, $attachment]))
            ->assertForbidden();
        $this->actingAs($client)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.internal-notes.attachments.excel', [$project, $note, $excel]))
            ->assertForbidden();
    }

    public function test_project_member_can_view_pending_ai_proposal_without_changing_project_data(): void
    {
        [$user, $workspace, $project] = $this->projectOwner('internal');
        $proposal = $this->proposal($project, $user);

        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.ai-proposals.show', [$project, $proposal]));

        $response->assertOk()
            ->assertSee('新しいタスクを登録する')
            ->assertSee('承認待ち')
            ->assertSee('承認待ちから外す')
            ->assertSee('現在は閲覧のみです');
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseHas('ai_proposals', ['id' => $proposal->id, 'status' => 'pending']);
    }

    public function test_proposal_page_shows_japanese_work_hierarchy_and_entity_counts(): void
    {
        [$user, $workspace, $project] = $this->projectOwner('outline');
        $roadmap = Roadmap::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => '会社の価値を伝える',
            'created_by' => $user->id,
        ]);
        $improvement = Improvement::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'roadmap_id' => $roadmap->id,
            'title' => '公式サイトを見直す',
            'proposed_by' => $user->id,
            'assigned_to' => $user->id,
        ]);
        $proposal = AiProposal::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'source' => 'codex',
            'idempotency_key' => 'outline-001',
            'title' => '公式サイト改善の提案',
            'summary' => '人とAIが一緒に仕事を進める価値を伝えます。',
            'status' => AiProposal::STATUS_PENDING,
        ]);
        $proposal->items()->createMany([
            ['operation' => 'update', 'entity_type' => 'improvement', 'target_public_id' => $improvement->public_id, 'attributes' => ['status' => 'planned'], 'sort_order' => 10],
            ['operation' => 'create', 'entity_type' => 'task', 'attributes' => ['title' => '中核メッセージを整理する', 'improvement_public_id' => $improvement->public_id], 'sort_order' => 20],
            ['operation' => 'create', 'entity_type' => 'task', 'attributes' => ['title' => '利用例を掲載する', 'improvement_public_id' => $improvement->public_id], 'sort_order' => 30],
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.ai-proposals.show', [$project, $proposal]))
            ->assertOk()
            ->assertSee('ロードマップ 1．会社の価値を伝える')
            ->assertSee('取り組み 1．公式サイトを見直す')
            ->assertSee('中核メッセージを整理する')
            ->assertSee('利用例を掲載する')
            ->assertSee('proposal-roadmap', false)
            ->assertSee('proposal-improvement', false)
            ->assertSee('proposal-task', false)
            ->assertSee('既存')
            ->assertSee('既存・更新あり')
            ->assertSee('新設')
            ->assertSeeInOrder(['プロジェクト全体への影響', 'ロードマップ', '1', '→', '1', '取り組み', '1', '→', '1', 'タスク', '0', '→', '2'])
            ->assertSee('技術的な変更内容');
    }

    public function test_ai_proposal_switches_to_its_workspace_for_an_authorized_member(): void
    {
        [$user, $workspace, $project] = $this->projectOwner('internal');
        $proposal = $this->proposal($project, $user);
        $otherWorkspace = Workspace::create([
            'organization_id' => $workspace->organization_id,
            'owner_user_id' => $user->id,
            'name' => 'Client WS',
            'slug' => 'client',
        ]);
        $user->workspaces()->attach($otherWorkspace->id, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $otherWorkspace->id])
            ->get(route('projects.ai-proposals.show', [$project, $proposal]))
            ->assertOk()
            ->assertSessionHas('current_workspace_id', $workspace->id);
    }

    public function test_idempotency_key_is_unique_within_workspace_and_source(): void
    {
        [$user, , $project] = $this->projectOwner('internal');
        $this->proposal($project, $user);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->proposal($project, $user);
    }

    public function test_project_admin_can_apply_hierarchical_create_proposal_atomically(): void
    {
        [$user, $workspace, $project] = $this->projectOwner('internal');
        $proposal = AiProposal::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'source' => 'codex',
            'idempotency_key' => 'hierarchy-001',
            'title' => '計画一括登録',
            'status' => AiProposal::STATUS_PENDING,
        ]);
        $proposal->items()->createMany([
            ['operation' => 'create', 'entity_type' => 'roadmap', 'reference_key' => 'roadmap-1', 'attributes' => ['title' => 'AI Roadmap'], 'sort_order' => 10],
            ['operation' => 'create', 'entity_type' => 'improvement', 'reference_key' => 'improvement-1', 'parent_reference' => 'roadmap-1', 'attributes' => ['title' => 'AI Improvement'], 'sort_order' => 20],
            ['operation' => 'create', 'entity_type' => 'task', 'parent_reference' => 'improvement-1', 'attributes' => ['title' => 'AI Task'], 'sort_order' => 30],
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-proposals.apply', [$project, $proposal]))
            ->assertRedirect(route('projects.ai-proposals.show', [$project, $proposal]));

        $roadmap = Roadmap::where('title', 'AI Roadmap')->firstOrFail();
        $improvement = Improvement::where('title', 'AI Improvement')->firstOrFail();
        $task = Task::where('title', 'AI Task')->firstOrFail();
        $this->assertSame($roadmap->id, $improvement->roadmap_id);
        $this->assertSame($improvement->id, $task->improvement_id);
        $this->assertSame(AiProposal::STATUS_APPLIED, $proposal->fresh()->status);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.ai-proposals.show', [$project, $proposal]))
            ->assertOk()
            ->assertSee('反映済み／Codexは待機中')
            ->assertSee('Codexへ作業開始を伝える')
            ->assertSee("プロジェクト「{$project->name}」", false)
            ->assertSee('現在のプロジェクト計画を確認し、承認内容に基づく次の作業を開始してください。');

        Carbon::setTestNow('2026-07-20 12:34:00 UTC');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-proposals.handoff', [$project, $proposal]))
            ->assertRedirect(route('projects.ai-proposals.show', [$project, $proposal]));

        $proposal->refresh();
        $this->assertSame($user->id, $proposal->handed_off_by);
        $this->assertNotNull($proposal->handed_off_at);

        $this->get(route('projects.ai-proposals.show', [$project, $proposal]))
            ->assertOk()
            ->assertSee('Codexへ伝達済み')
            ->assertSee('2026/07/20 21:34')
            ->assertSee('伝達済み');

        Carbon::setTestNow();
    }

    public function test_invalid_proposal_cannot_be_applied(): void
    {
        [$user, $workspace, $project] = $this->projectOwner('internal');
        $proposal = AiProposal::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'source' => 'codex',
            'idempotency_key' => 'invalid-001',
            'title' => '不正な提案',
            'status' => AiProposal::STATUS_PENDING,
        ]);
        $proposal->items()->create([
            'operation' => 'create',
            'entity_type' => 'task',
            'attributes' => ['title' => 'Unsafe Task', 'password' => 'secret'],
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-proposals.apply', [$project, $proposal]))
            ->assertSessionHasErrors('proposal');

        $this->assertDatabaseMissing('tasks', ['title' => 'Unsafe Task']);
        $this->assertSame(AiProposal::STATUS_PENDING, $proposal->fresh()->status);
    }

    public function test_project_admin_can_apply_hierarchical_delete_proposal_safely(): void
    {
        [$user, $workspace, $project] = $this->projectOwner('delete');
        $roadmap = Roadmap::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Default Roadmap',
            'created_by' => $user->id,
        ]);
        $improvement = Improvement::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'roadmap_id' => $roadmap->id,
            'title' => 'Default Improvement',
            'proposed_by' => $user->id,
            'assigned_to' => $user->id,
        ]);
        $proposal = AiProposal::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'source' => 'codex',
            'idempotency_key' => 'delete-001',
            'title' => 'Delete defaults',
            'status' => AiProposal::STATUS_PENDING,
        ]);
        $proposal->items()->createMany([
            ['operation' => 'delete', 'entity_type' => 'roadmap', 'target_public_id' => $roadmap->public_id, 'attributes' => [], 'sort_order' => 10],
            ['operation' => 'delete', 'entity_type' => 'improvement', 'target_public_id' => $improvement->public_id, 'attributes' => [], 'sort_order' => 20],
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.ai-proposals.show', [$project, $proposal]))
            ->assertOk()
            ->assertSeeInOrder(['ロードマップ', '1', '→', '0', '取り組み', '1', '→', '0', 'タスク', '0', '→', '0']);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-proposals.apply', [$project, $proposal]))
            ->assertRedirect(route('projects.ai-proposals.show', [$project, $proposal]));

        $this->assertSoftDeleted($improvement);
        $this->assertSoftDeleted($roadmap);
        $this->assertSame(AiProposal::STATUS_APPLIED, $proposal->fresh()->status);
    }

    public function test_delete_proposal_is_invalid_when_children_would_remain(): void
    {
        [$user, $workspace, $project] = $this->projectOwner('unsafe-delete');
        $roadmap = Roadmap::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Roadmap With Child',
            'created_by' => $user->id,
        ]);
        Improvement::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'roadmap_id' => $roadmap->id,
            'title' => 'Child Improvement',
            'proposed_by' => $user->id,
            'assigned_to' => $user->id,
        ]);
        $proposal = AiProposal::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'source' => 'codex',
            'idempotency_key' => 'delete-unsafe-001',
            'title' => 'Unsafe delete',
            'status' => AiProposal::STATUS_PENDING,
        ]);
        $proposal->items()->create([
            'operation' => 'delete',
            'entity_type' => 'roadmap',
            'target_public_id' => $roadmap->public_id,
            'attributes' => [],
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-proposals.apply', [$project, $proposal]))
            ->assertSessionHasErrors('proposal');

        $this->assertNotSoftDeleted($roadmap);
        $this->assertStringContainsString('取り組みが残っている', $proposal->items()->firstOrFail()->fresh()->validation_message);
    }

    private function proposal(Project $project, User $user): AiProposal
    {
        $proposal = AiProposal::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id,
            'source' => 'codex',
            'idempotency_key' => 'request-001',
            'title' => '開発計画の追加提案',
            'summary' => '本データへ反映する前の提案です。',
            'status' => AiProposal::STATUS_PENDING,
            'requested_by' => $user->id,
            'evidence' => ['conversation' => 'ユーザーからの依頼'],
        ]);

        AiProposalItem::create([
            'ai_proposal_id' => $proposal->id,
            'operation' => AiProposalItem::OPERATION_CREATE,
            'entity_type' => 'task',
            'attributes' => ['title' => '新しいタスクを登録する', 'priority' => 'high'],
        ]);

        return $proposal;
    }

    private function projectOwner(string $slug): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Rise Gate', 'slug' => 'rise-gate-'.$slug]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'owner_user_id' => $user->id,
            'name' => '社内WS',
            'slug' => $slug,
        ]);
        $organization->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $user->workspaces()->attach($workspace->id, ['role' => 'owner', 'joined_at' => now()]);
        $project = Project::create([
            'organization_id' => $organization->id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $user->id,
            'name' => 'RISE GATE OS',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'project_role' => ProjectMember::ROLE_OWNER,
            'permission_level' => ProjectMember::PERMISSION_ADMIN,
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);

        return [$user, $workspace, $project];
    }
}
