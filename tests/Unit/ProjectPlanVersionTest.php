<?php

namespace Tests\Unit;

use App\Models\ProjectPlanVersion;
use PHPUnit\Framework\TestCase;

class ProjectPlanVersionTest extends TestCase
{
    public function test_legacy_ai_proposal_version_uses_the_snapshot_from_before_application(): void
    {
        $version = new ProjectPlanVersion([
            'version_type' => ProjectPlanVersion::TYPE_PROPOSAL,
            'previous_snapshot' => ['project' => ['name' => 'Before']],
            'plan_snapshot' => ['project' => ['name' => 'After']],
        ]);

        $this->assertSame('Before', data_get($version->timelineSnapshot(), 'project.name'));
    }

    public function test_new_ai_proposal_before_version_uses_its_plan_snapshot(): void
    {
        $version = new ProjectPlanVersion([
            'version_type' => ProjectPlanVersion::TYPE_PROPOSAL_BEFORE,
            'previous_snapshot' => ['project' => ['name' => 'Earlier history']],
            'plan_snapshot' => ['project' => ['name' => 'Immediately before']],
        ]);

        $this->assertSame('Immediately before', data_get($version->timelineSnapshot(), 'project.name'));
    }
}
