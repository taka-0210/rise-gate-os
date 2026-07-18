<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommonStaffPlatformPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_common_staff_platform_plan_is_seeded_with_expected_structure(): void
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Rise Up',
            'slug' => 'rise-up',
        ]);
        $workspace = Workspace::query()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $user->id,
            'name' => 'Rise Up',
            'slug' => 'rise-up',
        ]);

        $project = Project::query()->create([
            'organization_id' => $organization->id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $user->id,
            'name' => '共通スタッフ基盤システム',
            'code' => 'HIT-STAFF',
            'status' => Project::STATUS_ACTIVE,
            'priority' => Project::PRIORITY_HIGH,
        ]);

        $this->seed(\Database\Seeders\CommonStaffPlatformProjectSeeder::class);

        $this->assertSame(4, $project->roadmaps()->count());
        $this->assertSame(9, $project->improvements()->count());
        $this->assertSame(28, $project->tasks()->count());
        $this->assertSame(
            'Phase 1：共通スタッフ基盤の方針とデータ設計',
            $project->roadmaps()->orderBy('sort_order')->first()->title
        );
    }
}
