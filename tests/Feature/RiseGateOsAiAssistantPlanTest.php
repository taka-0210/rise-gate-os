<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RiseGateOsAiAssistantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiseGateOsAiAssistantPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_assistant_plan_is_added_only_to_internal_workspace_project(): void
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'Rise Gate', 'slug' => 'rise-gate']);
        $clientWorkspace = $this->workspace($organization->id, $user->id, 'クライアント WS', 'client');
        $internalWorkspace = $this->workspace($organization->id, $user->id, '社内WS', 'internal');
        $clientProject = $this->project($organization->id, $clientWorkspace->id, $user->id);
        $internalProject = $this->project($organization->id, $internalWorkspace->id, $user->id);

        $this->seed(RiseGateOsAiAssistantSeeder::class);

        $this->assertSame(0, $clientProject->roadmaps()->count());
        $this->assertSame(1, $internalProject->roadmaps()->count());
        $this->assertSame(3, $internalProject->improvements()->count());
        $this->assertSame(13, $internalProject->tasks()->count());
        $this->assertSame(
            0,
            $internalProject->roadmaps()->where('title', 'AIアシスタントと本番環境を安全につなぐ')->value('sort_order')
        );
    }

    private function workspace(int $organizationId, int $ownerId, string $name, string $slug): Workspace
    {
        return Workspace::query()->create([
            'organization_id' => $organizationId,
            'owner_user_id' => $ownerId,
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    private function project(int $organizationId, int $workspaceId, int $ownerId): Project
    {
        return Project::query()->create([
            'organization_id' => $organizationId,
            'owning_workspace_id' => $workspaceId,
            'billing_workspace_id' => $workspaceId,
            'owner_user_id' => $ownerId,
            'name' => 'RISE GATE OS',
            'status' => Project::STATUS_ACTIVE,
            'priority' => Project::PRIORITY_NORMAL,
        ]);
    }
}
