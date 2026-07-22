<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevelopmentGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_member_can_open_the_development_guide(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Rise Gate', 'slug' => 'rise-gate-guide']);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'name' => 'Rise Gate Workspace',
            'slug' => 'rise-gate-workspace-guide',
        ]);

        $organization->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $workspace->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('development-guide'))
            ->assertOk()
            ->assertSee('新しい案件は、この順番で進めます。')
            ->assertSee('Pushだけではサイトは変わりません')
            ->assertSee('見せた後は、いったん止める')
            ->assertSee('開発の進め方');
    }

    public function test_guest_cannot_open_the_development_guide(): void
    {
        $this->get('/development-guide')->assertRedirect('/login');
    }
}
