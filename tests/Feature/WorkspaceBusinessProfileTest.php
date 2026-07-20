<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkspaceBusinessProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_owner_can_save_issuer_profile_bank_and_private_logo(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->workspace('owner');

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id, 'access_mode' => 'workspace'])
            ->put(route('workspace-business-profile.update'), [
                'legal_name' => '株式会社ライズアップ',
                'trade_name' => 'RISE GATE',
                'postal_code' => '900-0000',
                'address_line1' => '沖縄県那覇市テスト1-2-3',
                'phone' => '098-000-0000',
                'email' => 'info@example.com',
                'invoice_registration_number' => 'T1234567890123',
                'bank_name' => 'テスト銀行',
                'branch_name' => '本店',
                'account_type' => 'ordinary',
                'account_number' => '1234567',
                'account_holder' => 'カ）ライズアップ',
                'logo' => UploadedFile::fake()->image('rise-gate.png', 400, 120),
            ])->assertRedirect();

        $profile = $workspace->businessProfile()->firstOrFail();
        $this->assertSame('RISE GATE', $profile->trade_name);
        Storage::disk('local')->assertExists($profile->logo_path);
        $this->assertDatabaseHas('workspace_bank_accounts', ['workspace_id' => $workspace->id, 'account_number' => '1234567', 'is_default' => true]);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id, 'access_mode' => 'workspace'])
            ->get(route('workspace-business-profile.media', 'logo'))->assertOk();
    }

    public function test_registered_workspace_logo_and_issuer_are_used_in_client_plan(): void
    {
        Storage::fake('local');
        [$user, $workspace] = $this->workspace('owner');
        $profile = $workspace->businessProfile()->create([
            'legal_name' => '株式会社ライズアップ',
            'trade_name' => 'RISE GATE',
            'logo_path' => 'workspace-business/test/logo.png',
            'logo_original_name' => 'logo.png',
        ]);
        Storage::disk('local')->put($profile->logo_path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $client = Client::create(['organization_id' => $workspace->organization_id, 'workspace_id' => $workspace->id, 'name' => '提出先']);
        $project = Project::create([
            'organization_id' => $workspace->organization_id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'owner_user_id' => $user->id,
            'name' => '提出資料テスト',
        ]);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $user->id, 'workspace_id' => $workspace->id, 'project_role' => 'owner', 'permission_level' => 'admin', 'status' => 'active']);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id, 'access_mode' => 'workspace'])
            ->get(route('projects.client-plan', $project))
            ->assertOk()
            ->assertSee('RISE GATE')
            ->assertSee('class="issuer-block"', false)
            ->assertSee(route('projects.business-media', [$project, 'logo']), false);

    }

    public function test_regular_workspace_member_cannot_update_issuer_profile(): void
    {
        [$user, $workspace] = $this->workspace('member');
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id, 'access_mode' => 'workspace'])
            ->put(route('workspace-business-profile.update'), ['legal_name' => '変更不可'])
            ->assertForbidden();
    }

    public function test_document_center_has_a_clear_link_to_the_business_profile(): void
    {
        [$user, $workspace] = $this->workspace('owner');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id, 'access_mode' => 'workspace'])
            ->get(route('documents.index'))
            ->assertOk()
            ->assertSee('事業者情報を設定')
            ->assertSee(route('workspace-business-profile.edit'), false);
    }

    public function test_company_profile_can_be_saved_before_optional_bank_account_is_entered(): void
    {
        [$user, $workspace] = $this->workspace('owner');

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id, 'access_mode' => 'workspace'])
            ->put(route('workspace-business-profile.update'), [
                'legal_name' => '株式会社ライズアップ',
                'account_type' => 'ordinary',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('workspace_business_profiles', ['workspace_id' => $workspace->id, 'legal_name' => '株式会社ライズアップ']);
        $this->assertDatabaseCount('workspace_bank_accounts', 0);
    }

    public function test_required_fields_are_validated_when_a_bank_account_is_started(): void
    {
        [$user, $workspace] = $this->workspace('owner');

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id, 'access_mode' => 'workspace'])
            ->from(route('workspace-business-profile.edit'))
            ->put(route('workspace-business-profile.update'), [
                'legal_name' => '株式会社ライズアップ',
                'bank_name' => 'テスト銀行',
                'account_type' => 'ordinary',
            ])->assertRedirect(route('workspace-business-profile.edit'))
            ->assertSessionHasErrors(['account_number', 'account_holder']);
    }

    private function workspace(string $role): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => '事業者情報テスト', 'slug' => 'business-'.uniqid()]);
        $workspace = Workspace::create(['organization_id' => $organization->id, 'owner_user_id' => $role === 'owner' ? $user->id : null, 'name' => '発行元WS', 'slug' => 'issuer-'.uniqid(), 'status' => 'active']);
        $organization->users()->attach($user->id, ['role' => $role === 'owner' ? 'owner' : 'member', 'joined_at' => now()]);
        $workspace->users()->attach($user->id, ['role' => $role, 'joined_at' => now()]);
        return [$user, $workspace];
    }
}
