<?php

namespace Tests\Feature;

use App\Models\AiAccessKey;
use App\Models\AiProposal;
use App\Models\AiProposalItem;
use App\Models\AiRequest;
use App\Models\AiRequestAttachment;
use App\Models\Improvement;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Services\AiProposalContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiProposalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_key_can_create_pending_proposal_without_changing_project_data(): void
    {
        [$workspace, $project] = $this->workspaceProject('internal');
        $token = $this->accessKey($workspace);

        $response = $this->withToken($token)->postJson('/api/v1/ai/proposals', $this->payload($project));

        $response->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('items_count', 1)
            ->assertJsonPath('valid_items_count', 1)
            ->assertJsonPath('invalid_items_count', 0);
        $this->assertDatabaseHas('ai_proposals', [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'idempotency_key' => 'codex-session-001',
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_api_accepts_project_metadata_update_as_a_pending_proposal(): void
    {
        [$workspace, $project] = $this->workspaceProject('project-metadata');
        $payload = [
            'project_public_id' => $project->public_id,
            'contract_version' => AiProposalContract::VERSION,
            'expected_project_version' => $project->plan_version,
            'idempotency_key' => 'project-metadata-001',
            'title' => 'Project基本情報の更新',
            'items' => [[
                'operation' => 'update',
                'entity_type' => 'project',
                'target_public_id' => $project->public_id,
                'expected_version' => $project->plan_version,
                'attributes' => [
                    'summary' => 'AIが提案した概要',
                    'current_state' => 'AIが整理した現状',
                    'desired_future_state' => 'AIが描いた未来のカタチ',
                ],
            ]],
        ];

        $this->withToken($this->accessKey($workspace))
            ->postJson('/api/v1/ai/proposals', $payload)
            ->assertCreated()
            ->assertJsonPath('valid_items_count', 1)
            ->assertJsonPath('invalid_items_count', 0);

        $project->refresh();
        $this->assertNull($project->summary);
        $this->assertNull($project->current_state);
        $this->assertNull($project->desired_future_state);
    }

    public function test_api_rejects_mojibake_before_saving_a_proposal(): void
    {
        [$workspace, $project] = $this->workspaceProject('mojibake');
        $payload = $this->payload($project);
        $payload['title'] = 'Project???????';
        $payload['items'][0]['attributes']['title'] = '文字化け????';

        $this->withToken($this->accessKey($workspace))
            ->postJson('/api/v1/ai/proposals', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('proposal');

        $this->assertDatabaseCount('ai_proposals', 0);
    }

    public function test_same_idempotency_key_returns_existing_proposal_without_duplication(): void
    {
        [$workspace, $project] = $this->workspaceProject('internal');
        $token = $this->accessKey($workspace);

        $firstId = $this->withToken($token)->postJson('/api/v1/ai/proposals', $this->payload($project))->json('proposal_id');
        $second = $this->withToken($token)->postJson('/api/v1/ai/proposals', $this->payload($project));

        $second->assertOk()->assertJsonPath('proposal_id', $firstId)->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('ai_proposals', 1);
        $this->assertDatabaseCount('ai_proposal_items', 1);
    }

    public function test_same_idempotency_key_rejects_different_proposal_content(): void
    {
        [$workspace, $project] = $this->workspaceProject('idempotency-mismatch');
        $token = $this->accessKey($workspace);
        $first = $this->payload($project);
        $first['evidence']['source'] = 'same-request';
        $second = $first;
        $second['title'] = 'Different proposal content';

        $this->withToken($token)->postJson('/api/v1/ai/proposals', $first)->assertCreated();
        $this->withToken($token)->postJson('/api/v1/ai/proposals', $second)->assertStatus(409);

        $this->assertDatabaseCount('ai_proposals', 1);
        $this->assertDatabaseCount('ai_proposal_items', 1);

        $sameWithDifferentKeyOrder = $first;
        $sameWithDifferentKeyOrder['evidence'] = array_reverse($first['evidence'], true);
        $this->withToken($token)->postJson('/api/v1/ai/proposals', $sameWithDifferentKeyOrder)
            ->assertOk()
            ->assertJsonPath('duplicate', true);
    }

    public function test_key_cannot_create_proposal_in_another_workspace(): void
    {
        [$workspace] = $this->workspaceProject('internal');
        [, $otherProject] = $this->workspaceProject('client');

        $this->withToken($this->accessKey($workspace))
            ->postJson('/api/v1/ai/proposals', $this->payload($otherProject))
            ->assertNotFound();
        $this->assertDatabaseCount('ai_proposals', 0);
    }

    public function test_missing_expired_or_wrong_scope_key_is_rejected(): void
    {
        [$workspace, $project] = $this->workspaceProject('internal');
        $this->postJson('/api/v1/ai/proposals', $this->payload($project))->assertUnauthorized();
        $wrongScope = $this->accessKey($workspace, ['proposals:read']);
        $this->withToken($wrongScope)->postJson('/api/v1/ai/proposals', $this->payload($project))->assertForbidden();
        $expired = $this->accessKey($workspace, [AiAccessKey::SCOPE_PROPOSALS_CREATE], now()->subMinute());
        $this->withToken($expired)->postJson('/api/v1/ai/proposals', $this->payload($project))->assertUnauthorized();
    }

    public function test_invalid_attributes_are_saved_as_non_applicable_preview_errors(): void
    {
        [$workspace, $project] = $this->workspaceProject('internal');
        $payload = $this->payload($project);
        $payload['items'][0]['attributes']['password'] = 'must-not-be-accepted';
        $payload['items'][0]['attributes']['priority'] = 'impossible';

        $this->withToken($this->accessKey($workspace))
            ->postJson('/api/v1/ai/proposals', $payload)
            ->assertCreated()
            ->assertJsonPath('valid_items_count', 0)
            ->assertJsonPath('invalid_items_count', 1);

        $this->assertDatabaseHas('ai_proposal_items', ['validation_status' => 'invalid']);
        $message = AiProposalItem::firstOrFail()->validation_message;
        $this->assertStringContainsString('password', $message);
        $this->assertStringContainsString('priority', $message);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_api_rejects_delete_proposal_in_scope_one(): void
    {
        [$workspace, $project, $user] = $this->workspaceProject('delete-api');
        $roadmap = Roadmap::create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Empty Default Roadmap',
            'created_by' => $user->id,
        ]);

        $payload = [
            'project_public_id' => $project->public_id,
            'contract_version' => AiProposalContract::VERSION,
            'expected_project_version' => $project->fresh()->plan_version,
            'idempotency_key' => 'delete-api-001',
            'title' => 'Delete empty default',
            'items' => [[
                'operation' => 'delete',
                'entity_type' => 'roadmap',
                'target_public_id' => $roadmap->public_id,
                'expected_version' => $roadmap->plan_version,
                'attributes' => [],
            ]],
        ];

        $this->withToken($this->accessKey($workspace))
            ->postJson('/api/v1/ai/proposals', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.operation');

        $this->assertNotSoftDeleted($roadmap);
    }

    public function test_api_rejects_unknown_contract_timeline_financial_and_role_changes(): void
    {
        [$workspace, $project] = $this->workspaceProject('scope-boundary');
        $token = $this->accessKey($workspace);

        $unknown = $this->payload($project);
        $unknown['contract_version'] = 'project-plan.v999';
        $this->withToken($token)->postJson('/api/v1/ai/proposals', $unknown)
            ->assertUnprocessable()->assertJsonValidationErrors('contract_version');

        $timeline = $this->payload($project);
        $timeline['idempotency_key'] = 'timeline-boundary';
        $timeline['mode'] = AiProposal::MODE_REPLACE_TIMELINE;
        $this->withToken($token)->postJson('/api/v1/ai/proposals', $timeline)
            ->assertUnprocessable()->assertJsonValidationErrors('mode');

        $financial = $this->payload($project);
        $financial['idempotency_key'] = 'financial-boundary';
        $financial['items'][0]['entity_type'] = 'financial_period';
        $this->withToken($token)->postJson('/api/v1/ai/proposals', $financial)
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.entity_type');

        $role = $this->payload($project);
        $role['idempotency_key'] = 'role-boundary';
        $role['items'][0] = [
            'operation' => 'update', 'entity_type' => 'project',
            'target_public_id' => $project->public_id, 'expected_version' => $project->plan_version,
            'attributes' => ['owner_user_id' => 999],
        ];
        $this->withToken($token)->postJson('/api/v1/ai/proposals', $role)
            ->assertCreated()->assertJsonPath('valid_items_count', 0)->assertJsonPath('invalid_items_count', 1);
        $this->assertNotSame(999, $project->fresh()->owner_user_id);
    }

    public function test_member_key_only_reads_projects_the_member_has_joined(): void
    {
        [$workspace, $visibleProject, $user] = $this->workspaceProject('internal');
        $hiddenProject = Project::create([
            'organization_id' => $workspace->organization_id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $user->id,
            'name' => 'Hidden Project',
        ]);
        ProjectMember::updateOrCreate([
            'project_id' => $visibleProject->id,
            'user_id' => $user->id,
        ], [
            'workspace_id' => $workspace->id,
            'project_role' => ProjectMember::ROLE_OWNER,
            'permission_level' => ProjectMember::PERMISSION_ADMIN,
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);
        $roadmap = Roadmap::create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $visibleProject->id,
            'title' => 'Visible Roadmap',
            'created_by' => $user->id,
        ]);
        $improvement = Improvement::create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $visibleProject->id,
            'roadmap_id' => $roadmap->id,
            'title' => 'Visible Improvement',
            'proposed_by' => $user->id,
        ]);
        Task::create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $visibleProject->id,
            'improvement_id' => $improvement->id,
            'title' => 'Visible Task',
            'created_by' => $user->id,
        ]);
        $token = $this->accessKey($workspace, [AiAccessKey::SCOPE_PROJECTS_READ], null, $user);

        $this->withToken($token)->getJson('/api/v1/ai/projects')
            ->assertOk()
            ->assertJsonCount(1, 'projects')
            ->assertJsonPath('projects.0.name', $visibleProject->name)
            ->assertJsonMissing(['name' => $hiddenProject->name]);

        $this->withToken($token)->getJson('/api/v1/ai/projects/'.$visibleProject->public_id)
            ->assertOk()
            ->assertJsonPath('project.roadmaps.0.improvements.0.tasks.0.title', 'Visible Task');

        $this->withToken($token)->getJson('/api/v1/ai/projects/'.$hiddenProject->public_id)
            ->assertNotFound();
    }

    public function test_forbidden_plan_categories_do_not_expose_counts_or_load_hierarchy(): void
    {
        [$workspace, $project, $user] = $this->workspaceProject('category-filter');
        $roadmap = Roadmap::create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Hidden Roadmap',
            'created_by' => $user->id,
        ]);
        $improvement = Improvement::create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'roadmap_id' => $roadmap->id,
            'title' => 'Hidden Theme',
            'proposed_by' => $user->id,
        ]);
        Task::create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'improvement_id' => $improvement->id,
            'title' => 'Hidden Action',
            'created_by' => $user->id,
        ]);
        $token = $this->accessKey($workspace, [AiAccessKey::SCOPE_PROJECTS_READ], null, $user);
        $workspace->aiSetting()->update(['allowed_data_categories' => ['project_metadata']]);

        $this->withToken($token)->getJson('/api/v1/ai/projects')
            ->assertOk()
            ->assertJsonPath('projects.0.roadmaps_count', null)
            ->assertJsonPath('projects.0.improvements_count', null)
            ->assertJsonPath('projects.0.tasks_count', null)
            ->assertJsonMissing(['title' => 'Hidden Roadmap']);

        $this->withToken($token)->getJson('/api/v1/ai/projects/'.$project->public_id)
            ->assertOk()
            ->assertJsonPath('project.roadmaps', [])
            ->assertJsonMissing(['title' => 'Hidden Roadmap'])
            ->assertJsonMissing(['title' => 'Hidden Theme'])
            ->assertJsonMissing(['title' => 'Hidden Action']);
    }

    public function test_project_plan_context_enforces_entity_limit_and_omits_execution_fields(): void
    {
        [$workspace, $project, $user] = $this->workspaceProject('context-bound');
        Roadmap::create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Bounded Roadmap',
            'status' => Roadmap::STATUS_COMPLETED,
            'created_by' => $user->id,
        ]);
        $token = $this->accessKey($workspace, [AiAccessKey::SCOPE_PROJECTS_READ], null, $user);

        $response = $this->withToken($token)->getJson('/api/v1/ai/projects/'.$project->public_id)->assertOk();
        $context = $response->json('project');
        $this->assertArrayNotHasKey('status', $context);
        $this->assertArrayNotHasKey('priority', $context);
        $this->assertArrayNotHasKey('owner_user_id', $context);
        $this->assertArrayNotHasKey('status', $context['roadmaps'][0]);

        config(['services.ai.scope_one_context_max_entities' => 0]);
        $this->withToken($token)->getJson('/api/v1/ai/projects/'.$project->public_id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ai_context');
    }

    public function test_codex_mcp_exposes_read_and_pending_proposal_tools(): void
    {
        Storage::fake('local');
        [$workspace, $project, $user] = $this->workspaceProject('mcp');
        ProjectMember::updateOrCreate([
            'project_id' => $project->id,
            'user_id' => $user->id,
        ], [
            'workspace_id' => $workspace->id,
            'project_role' => ProjectMember::ROLE_OWNER,
            'permission_level' => ProjectMember::PERMISSION_ADMIN,
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);
        $token = $this->accessKey($workspace, [AiAccessKey::SCOPE_PROJECTS_READ, AiAccessKey::SCOPE_PROPOSALS_CREATE], null, $user);

        $this->withToken($token)->postJson('/api/mcp/rise-gate-os', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Codex', 'version' => 'test']],
        ])->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'rise-gate-os')
            ->assertJsonPath('result.instructions', fn (string $text): bool => str_contains($text, 'UTF-8'));

        $this->withToken($token)->postJson('/api/mcp/rise-gate-os', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass,
        ])->assertOk()
            ->assertJsonCount(7, 'result.tools')
            ->assertJsonPath('result.tools.3.name', 'submit_handoff_proposal');

        $this->withToken($token)->postJson('/api/mcp/rise-gate-os', [
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'list_projects', 'arguments' => new \stdClass],
        ])->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.projects.0.name', $project->name);

        $aiRequest = AiRequest::create([
            'organization_id' => $project->organization_id, 'workspace_id' => $workspace->id,
            'project_id' => $project->id, 'requested_by' => $user->id,
            'title' => '計画を提案して', 'instructions' => '次のロードマップを考えてください。',
            'status' => AiRequest::STATUS_PENDING,
        ]);
        Storage::disk('local')->put('ai-requests/test/board.jpg', 'image-bytes');
        $attachment = AiRequestAttachment::create([
            'ai_request_id' => $aiRequest->id, 'workspace_id' => $workspace->id,
            'project_id' => $project->id, 'uploaded_by' => $user->id,
            'original_name' => '現状ボード.jpg', 'stored_path' => 'ai-requests/test/board.jpg',
            'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size_bytes' => 11,
            'sha256' => hash('sha256', 'image-bytes'),
        ]);
        $this->withToken($token)->postJson('/api/mcp/rise-gate-os', [
            'jsonrpc' => '2.0', 'id' => 31, 'method' => 'tools/call', 'params' => ['name' => 'list_ai_requests', 'arguments' => new \stdClass],
        ])->assertOk()
            ->assertJsonPath('result.structuredContent.requests.0.public_id', $aiRequest->public_id)
            ->assertJsonPath('result.structuredContent.requests.0.attachments.0.public_id', $attachment->public_id);
        $this->withToken($token)->postJson('/api/mcp/rise-gate-os', [
            'jsonrpc' => '2.0', 'id' => 32, 'method' => 'tools/call', 'params' => ['name' => 'claim_ai_request', 'arguments' => ['request_public_id' => $aiRequest->public_id]],
        ])->assertOk()->assertJsonPath('result.structuredContent.status', AiRequest::STATUS_PROCESSING);
        $this->withToken($token)->postJson('/api/mcp/rise-gate-os', [
            'jsonrpc' => '2.0', 'id' => 33, 'method' => 'tools/call', 'params' => ['name' => 'get_ai_request_attachment', 'arguments' => [
                'request_public_id' => $aiRequest->public_id, 'attachment_public_id' => $attachment->public_id,
            ]],
        ])->assertOk()
            ->assertJsonPath('result.content.0.type', 'image')
            ->assertJsonPath('result.content.0.data', base64_encode('image-bytes'))
            ->assertJsonPath('result.structuredContent.attachment.name', '現状ボード.jpg');
        $otherToken = $this->accessKey($workspace, [AiAccessKey::SCOPE_PROJECTS_READ], null, $user);
        $this->withToken($otherToken)->postJson('/api/mcp/rise-gate-os', [
            'jsonrpc' => '2.0', 'id' => 34, 'method' => 'tools/call', 'params' => ['name' => 'get_ai_request_attachment', 'arguments' => [
                'request_public_id' => $aiRequest->public_id, 'attachment_public_id' => $attachment->public_id,
            ]],
        ])->assertOk()->assertJsonPath('result.isError', true);

        $arguments = $this->payload($project) + ['ai_request_public_id' => $aiRequest->public_id];
        $brokenArguments = $arguments;
        $brokenArguments['idempotency_key'] = 'mojibake-mcp-001';
        $brokenArguments['summary'] = 'Web????????????????';
        $this->withToken($token)->postJson('/api/mcp/rise-gate-os', [
            'jsonrpc' => '2.0', 'id' => 35, 'method' => 'tools/call',
            'params' => ['name' => 'submit_proposal', 'arguments' => $brokenArguments],
        ])->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', fn (string $text): bool => str_contains($text, '文字化け'));
        $this->assertDatabaseCount('ai_proposals', 0);

        $this->withToken($token)->postJson('/api/mcp/rise-gate-os', [
            'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
            'params' => ['name' => 'submit_proposal', 'arguments' => $arguments],
        ])->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.status', 'pending');

        $this->assertDatabaseHas('ai_proposals', ['requested_by' => $user->id, 'project_id' => $project->id]);
        $this->assertDatabaseHas('ai_requests', ['id' => $aiRequest->id, 'status' => AiRequest::STATUS_PROPOSED]);
        $this->assertDatabaseHas('ai_audit_logs', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'project_id' => $project->id,
            'event' => 'mcp.tool_called',
            'tool_name' => 'submit_proposal',
            'succeeded' => true,
        ]);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_mcp_rejects_untrusted_origin(): void
    {
        [$workspace] = $this->workspaceProject('origin');
        $token = $this->accessKey($workspace, [AiAccessKey::SCOPE_PROJECTS_READ]);

        $this->withToken($token)->withHeader('Origin', 'https://evil.example')
            ->postJson('/api/mcp/rise-gate-os', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertForbidden()
            ->assertJsonPath('error.code', -32000);
    }

    private function payload(Project $project): array
    {
        return [
            'project_public_id' => $project->public_id,
            'contract_version' => AiProposalContract::VERSION,
            'expected_project_version' => $project->plan_version,
            'idempotency_key' => 'codex-session-001',
            'title' => 'Project plan proposal',
            'summary' => 'Create one roadmap draft.',
            'evidence' => ['tests' => ['AiProposalApiTest']],
            'items' => [[
                'operation' => 'create',
                'entity_type' => 'roadmap',
                'expected_version' => $project->plan_version,
                'attributes' => ['title' => 'API integration check'],
            ]],
        ];

    }

    private function accessKey(Workspace $workspace, array $scopes = [AiAccessKey::SCOPE_PROPOSALS_CREATE], $expiresAt = null, ?User $user = null): string
    {
        $user ??= $workspace->owner;
        WorkspaceAiSetting::updateOrCreate(['workspace_id' => $workspace->id], [
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
            'scopes' => $scopes,
            'expires_at' => $expiresAt ?: now()->addHour(),
        ]);

        return $token;
    }

    private function workspaceProject(string $slug): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Org '.$slug, 'slug' => 'org-'.$slug]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'owner_user_id' => $user->id,
            'name' => 'WS '.$slug,
            'slug' => $slug,
        ]);
        $organization->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $user->workspaces()->attach($workspace->id, ['role' => 'owner', 'joined_at' => now()]);
        $project = Project::create([
            'organization_id' => $organization->id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $user->id,
            'name' => 'Project '.$slug,
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'project_role' => ProjectMember::ROLE_OWNER,
            'permission_level' => ProjectMember::PERMISSION_ADMIN,
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);

        return [$workspace, $project, $user];
    }
}
