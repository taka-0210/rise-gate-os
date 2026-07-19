<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Roadmap;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstimateFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_owner_can_create_a_snapshot_estimate_from_a_roadmap(): void
    {
        [$user, $workspace, $project] = $this->project();
        $workspace->businessProfile()->create([
            'legal_name' => '株式会社ライズアップ',
            'trade_name' => 'RISE GATE',
        ]);
        $roadmap = Roadmap::create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => '要件を確定する',
            'purpose' => '見積対象',
            'status' => Roadmap::STATUS_DRAFT,
            'sort_order' => 1,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id, 'access_mode' => 'workspace'])
            ->post(route('projects.estimates.store', $project), [
                'title' => '給与計算システム 御見積',
                'issued_on' => '2026-07-19',
                'valid_until' => '2026-08-19',
                'discount' => 1000,
                'items' => [[
                    'selected' => 1,
                    'source_type' => 'roadmap',
                    'source_id' => $roadmap->id,
                    'description' => '給与計算システムの要件定義',
                    'quantity' => 2,
                    'unit' => '日',
                    'unit_price' => 50000,
                    'tax_rate' => 10,
                ]],
            ]);

        $estimate = $workspace->estimates()->firstOrFail();
        $response->assertRedirect(route('estimates.show', $estimate));
        $this->assertSame('EST-202607-0001', $estimate->estimate_number);
        $this->assertSame('株式会社ライズアップ', $estimate->issuer_snapshot['legal_name']);
        $this->assertSame(100000, $estimate->subtotal);
        $this->assertSame(108900, $estimate->total);
        $this->assertSame('給与計算システムの要件定義', $estimate->items()->firstOrFail()->description);

        $roadmap->update(['title' => '変更後の名称']);
        $workspace->businessProfile()->update(['legal_name' => '変更後の会社名']);
        $estimate->refresh();
        $this->assertSame('給与計算システムの要件定義', $estimate->items()->firstOrFail()->description);
        $this->assertSame('株式会社ライズアップ', $estimate->issuer_snapshot['legal_name']);

        $this->get(route('estimates.show', $estimate))
            ->assertOk()
            ->assertSee('給与計算システム 御見積')
            ->assertSee('給与計算システムの要件定義')
            ->assertSee('@page{size:A4 portrait;margin:12mm 14mm}', false)
            ->assertSee('display:table-header-group', false)
            ->assertSee('break-inside:avoid', false)
            ->assertSee('class="print-blank-row"', false)
            ->assertSee('height:9mm', false);
    }

    public function test_estimate_cannot_be_viewed_from_another_current_workspace(): void
    {
        [$user, $workspace, $project] = $this->project();
        $estimate = $workspace->estimates()->create([
            'project_id' => $project->id,
            'client_id' => $project->client_id,
            'estimate_number' => 'EST-202607-0001',
            'title' => '非公開見積',
            'issued_on' => '2026-07-19',
            'status' => 'draft',
            'issuer_snapshot' => ['legal_name' => '発行元'],
            'client_snapshot' => ['name' => '提出先'],
            'created_by' => $user->id,
        ]);
        $other = Workspace::create([
            'organization_id' => $workspace->organization_id,
            'owner_user_id' => $user->id,
            'name' => '別WS',
            'slug' => 'other-'.uniqid(),
            'status' => 'active',
        ]);
        $other->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id, 'access_mode' => 'workspace'])
            ->get(route('estimates.show', $estimate))
            ->assertNotFound();
    }

    public function test_estimate_can_be_duplicated_as_a_new_draft(): void
    {
        [$user, $workspace, $project] = $this->project();
        $estimate = $workspace->estimates()->create([
            'project_id' => $project->id,
            'client_id' => $project->client_id,
            'estimate_number' => 'EST-202607-0001',
            'title' => '初回見積',
            'issued_on' => '2026-07-19',
            'status' => 'issued',
            'issuer_snapshot' => ['legal_name' => '発行元'],
            'client_snapshot' => ['name' => '提出先'],
            'subtotal' => 50000,
            'tax_amount' => 5000,
            'total' => 55000,
            'created_by' => $user->id,
        ]);
        $estimate->items()->create([
            'description' => '設計費', 'quantity' => 1, 'unit' => '式',
            'unit_price' => 50000, 'tax_rate' => 10, 'amount' => 50000,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id, 'access_mode' => 'workspace'])
            ->post(route('estimates.duplicate', $estimate));

        $copy = $workspace->estimates()->whereKeyNot($estimate->id)->firstOrFail();
        $response->assertRedirect(route('estimates.show', $copy));
        $this->assertSame('draft', $copy->status);
        $this->assertSame('初回見積（複製）', $copy->title);
        $this->assertSame('設計費', $copy->items()->firstOrFail()->description);
    }

    public function test_project_can_be_estimated_as_one_package_without_plan_items(): void
    {
        [$user, $workspace, $project] = $this->project();

        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id, 'access_mode' => 'workspace'])
            ->post(route('projects.estimates.store', $project), [
                'title' => '一式見積',
                'issued_on' => '2026-07-19',
                'discount' => 5000,
                'items' => [[
                    'selected' => 1,
                    'source_type' => 'manual',
                    'source_id' => null,
                    'description' => '給与計算システム開発一式',
                    'quantity' => 1,
                    'unit' => '式',
                    'unit_price' => 300000,
                    'tax_rate' => 10,
                ]],
            ]);

        $estimate = $workspace->estimates()->firstOrFail();
        $response->assertRedirect(route('estimates.show', $estimate));
        $this->assertSame(324500, $estimate->total);
        $this->assertNull($estimate->items()->firstOrFail()->source_id);
    }

    public function test_selected_plan_items_become_zero_value_scope_when_package_is_selected(): void
    {
        [$user, $workspace, $project] = $this->project();
        $roadmap = Roadmap::create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => '導入準備', 'purpose' => '範囲', 'status' => 'draft',
            'sort_order' => 1, 'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id, 'access_mode' => 'workspace'])
            ->post(route('projects.estimates.store', $project), [
                'title' => '一式＋作業範囲', 'issued_on' => '2026-07-19',
                'items' => [
                    ['selected' => 1, 'source_type' => 'manual', 'source_id' => null, 'description' => '開発一式', 'quantity' => 1, 'unit' => '式', 'unit_price' => 300000, 'tax_rate' => 10],
                    ['selected' => 1, 'source_type' => 'roadmap', 'source_id' => $roadmap->id, 'description' => '導入準備', 'quantity' => 5, 'unit' => '日', 'unit_price' => 50000, 'tax_rate' => 10],
                ],
            ]);

        $estimate = $workspace->estimates()->firstOrFail();
        $this->assertSame(330000, $estimate->total);
        $scope = $estimate->items()->where('source_type', 'roadmap')->firstOrFail();
        $this->assertTrue($scope->is_scope_only);
        $this->assertSame(0, $scope->amount);
        $this->get(route('estimates.show', $estimate))->assertOk()->assertSee('作業範囲・実施内容')->assertSee('導入準備');
    }

    private function project(): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => '見積テスト', 'slug' => 'estimate-'.uniqid()]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'owner_user_id' => $user->id,
            'name' => '見積WS',
            'slug' => 'estimate-ws-'.uniqid(),
            'status' => 'active',
        ]);
        $organization->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $workspace->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $client = Client::create([
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'name' => '株式会社お客様',
        ]);
        $project = Project::create([
            'organization_id' => $organization->id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'owner_user_id' => $user->id,
            'name' => '給与計算システム',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'project_role' => 'owner',
            'permission_level' => 'admin',
            'status' => 'active',
        ]);

        return [$user, $workspace, $project];
    }
}
