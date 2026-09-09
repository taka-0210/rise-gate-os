<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectApp;
use App\Models\ProjectAppAccount;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StandaloneAppTest extends TestCase
{
    use RefreshDatabase;

    private function project(): array
    {
        $owner = User::factory()->create();
        $organization = Organization::create(['name' => 'Apps', 'slug' => 'apps-'.uniqid()]);
        $workspace = Workspace::create(['organization_id' => $organization->id, 'owner_user_id' => $owner->id, 'name' => 'Apps', 'slug' => 'apps-'.uniqid(), 'status' => 'active']);
        $organization->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
        $workspace->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
        $project = Project::create(['organization_id' => $organization->id, 'owning_workspace_id' => $workspace->id, 'billing_workspace_id' => $workspace->id, 'owner_user_id' => $owner->id, 'name' => '独立TODO']);
        ProjectMember::create(['project_id' => $project->id, 'workspace_id' => $workspace->id, 'user_id' => $owner->id, 'project_role' => 'owner', 'permission_level' => 'admin', 'status' => 'active']);

        return [$owner, $workspace, $project];
    }

    private function app(Project $project): ProjectApp
    {
        return ProjectApp::create(['project_id' => $project->id, 'created_by' => $project->owner_user_id, 'name' => 'TODO', 'html' => '<h1>TODO</h1>', 'version' => 1]);
    }

    private function account(ProjectApp $app, string $login = 'staff1', string $role = 'staff'): ProjectAppAccount
    {
        return ProjectAppAccount::create(['project_app_id' => $app->id, 'login' => $login, 'name' => $login, 'password' => 'test-password', 'role' => $role, 'enabled' => true]);
    }

    private function loginApp(ProjectApp $app, string $login): string
    {
        $response = $this->post(route('apps.login', $app), ['login' => $login, 'password' => 'test-password'])->assertRedirect(route('apps.run', $app));
        $cookie = $response->getCookie('rg_app_'.$app->public_id);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/apps/'.$app->public_id, $cookie->getPath());
        $token = $cookie->getValue();
        $this->withCredentials()->withCookie('rg_app_'.$app->public_id, $token);

        return $token;
    }

    public function test_editor_registers_a_todo_with_its_own_admin_and_public_login_url(): void
    {
        [$owner, $workspace, $project] = $this->project();
        $response = $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->postJson(route('projects.apps.store', $project), ['name' => '仕事TODO', 'template' => 'todo', 'admin_login' => 'admin', 'admin_password' => 'test-password'])
            ->assertCreated();
        $app = ProjectApp::firstOrFail();
        $this->assertStringContainsString('/apps/'.$app->public_id, $response->json('app.run_url'));
        $this->assertStringContainsString('riseGateApp.save', $app->html);
        $admin = ProjectAppAccount::firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check('test-password', $admin->password));
        $this->assertStringNotContainsString('test-password', $app->html);
        $this->getJson(route('apps.data.read', $app))->assertUnauthorized();
        $this->get(route('projects.workspace', $project))->assertOk()->assertSee('TODOのひな形から作成')->assertSee('アプリとして登録');
    }

    public function test_staff_can_save_and_read_from_a_new_app_session_without_os_login(): void
    {
        [, , $project] = $this->project();
        $app = $this->app($project);
        $this->account($app);
        $this->assertGuest();
        $this->get(route('apps.run', $app))->assertOk()->assertSee('このアプリ専用のアカウント');
        $first = $this->loginApp($app, 'staff1');
        $this->assertGuest();
        $this->getJson(route('apps.data.read', $app))->assertOk()->assertJsonPath('data', null)->assertJsonPath('revision', 0);
        $this->putJson(route('apps.data.write', $app), ['revision' => 0, 'data' => ['todos' => [['id' => 'one', 'title' => '共通データ', 'done' => false]]]])
            ->assertOk()->assertJsonPath('revision', 1)->assertJson(fn ($json) => $json->where('saved_at', fn ($date) => str_ends_with($date, '+09:00'))->etc());
        $second = $this->loginApp($app, 'staff1');
        $this->assertNotSame($first, $second);
        $this->getJson(route('apps.data.read', $app))->assertOk()->assertJsonPath('data.todos.0.title', '共通データ');
        $this->get(route('apps.run', $app))->assertOk()->assertSee('sandbox="allow-scripts allow-downloads"', false)
            ->assertDontSee('allow-same-origin')->assertSee('staff1')->assertDontSee('アカウント管理');
        $this->post(route('apps.logout', $app))->assertRedirect();
        $this->getJson(route('apps.data.read', $app))->assertUnauthorized();
    }

    public function test_staff_data_is_separate_and_staff_cannot_choose_another_account_or_manage_accounts(): void
    {
        [, , $project] = $this->project();
        $app = $this->app($project);
        $one = $this->account($app);
        $two = $this->account($app, 'staff2');
        $this->loginApp($app, 'staff1');
        $this->putJson(route('apps.data.write', $app), ['revision' => 0, 'data' => ['secret' => 'one']])->assertOk();
        $this->loginApp($app, 'staff2');
        $this->getJson(route('apps.data.read', $app))->assertJsonPath('data', null);
        $this->getJson(route('apps.data.read', ['projectApp' => $app, 'account' => $one->id]))->assertForbidden();
        $this->putJson(route('apps.data.write', ['projectApp' => $app, 'account' => $one->id]), ['data' => [], 'revision' => 1])->assertForbidden();
        $this->putJson(route('apps.data.write', $app), ['data' => [], 'revision' => 0, 'project_app_account_id' => $one->id])->assertUnprocessable();
        $this->get(route('apps.accounts', $app))->assertForbidden();
        $this->postJson(route('apps.accounts.store', $app), ['login' => 'staff3'])->assertForbidden();
    }

    public function test_admin_can_manage_staff_and_view_their_data_only_in_the_same_app(): void
    {
        [, , $project] = $this->project();
        $app = $this->app($project);
        $admin = $this->account($app, 'admin', 'admin');
        $staff = $this->account($app);
        $otherApp = $this->app($project);
        $other = $this->account($otherApp);
        $this->loginApp($app, 'admin');
        $this->get(route('apps.accounts', $app))->assertOk()->assertSee('staff1');
        $this->post(route('apps.accounts.store', $app), ['login' => 'staff3', 'name' => '三人目', 'password' => 'test-password', 'role' => 'staff'])->assertRedirect();
        $this->putJson(route('apps.data.write', ['projectApp' => $app, 'account' => $staff->id]), ['revision' => 0, 'data' => ['todos' => []]])->assertOk();
        $this->getJson(route('apps.data.read', ['projectApp' => $app, 'account' => $other->id]))->assertNotFound();
        $this->putJson(route('apps.accounts.update', [$app, $other]), ['name' => 'wrong', 'role' => 'staff', 'enabled' => false])->assertNotFound();
        $this->putJson(route('apps.accounts.update', [$app, $admin]), ['name' => 'admin', 'role' => 'staff', 'enabled' => true])->assertUnprocessable();
        $this->put(route('apps.accounts.update', [$app, $staff]), ['name' => '停止', 'role' => 'staff', 'enabled' => false])->assertRedirect();
        $this->post(route('apps.login', $app), ['login' => 'staff1', 'password' => 'test-password'])->assertSessionHasErrors('login');
    }

    public function test_stale_updates_are_rejected_and_source_rename_keeps_data(): void
    {
        [$owner, $workspace, $project] = $this->project();
        $app = $this->app($project);
        $this->account($app);
        $this->loginApp($app, 'staff1');
        $this->putJson(route('apps.data.write', $app), ['revision' => 0, 'data' => ['todos' => ['saved']]])->assertOk();
        $this->putJson(route('apps.data.write', $app), ['revision' => 0, 'data' => ['todos' => ['stale']]])->assertConflict();
        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->putJson(route('projects.apps.update', [$project, $app]), ['name' => '名前変更', 'html' => '<h1>新UI</h1>', 'version' => 1])->assertOk();
        $this->putJson(route('projects.apps.update', [$project, $app]), ['name' => '古い変更', 'html' => 'old', 'version' => 1])->assertConflict();
        $this->putJson(route('projects.apps.update', [$project, $app]), ['name' => '古い提案', 'html' => 'old', 'version' => 2, 'original_hash' => hash('sha256', '<h1>TODO</h1>')])->assertConflict();
        $this->getJson(route('apps.data.read', $app))->assertJsonPath('data.todos.0', 'saved');
    }

    public function test_app_cookie_cannot_authenticate_another_app_and_expired_sessions_fail(): void
    {
        [, , $project] = $this->project();
        $one = $this->app($project);
        $two = $this->app($project);
        $this->account($one);
        $token = $this->loginApp($one, 'staff1');
        $this->withCookie('rg_app_'.$two->public_id, $token)->getJson(route('apps.data.read', $two))->assertUnauthorized();
        DB::table('project_app_sessions')->update(['expires_at' => now()->subSecond()]);
        $this->getJson(route('apps.data.read', $one))->assertUnauthorized();
        $this->post(route('apps.login', $one), ['login' => 'staff1', 'password' => 'wrong-password'])->assertSessionHasErrors('login');
    }

    public function test_project_viewer_cannot_publish_and_project_boundary_is_checked(): void
    {
        [$owner, $workspace, $project] = $this->project();
        $app = $this->app($project);
        [, , $otherProject] = $this->project();
        $otherApp = $this->app($otherProject);
        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id]);
        $this->getJson(route('projects.apps.source', [$project, $otherApp]))->assertNotFound();
        ProjectMember::where('project_id', $project->id)->update(['permission_level' => 'view']);
        $this->getJson(route('projects.apps.source', [$project, $app]))->assertForbidden();
        $this->postJson(route('projects.apps.store', $project), ['name' => 'no'])->assertForbidden();
        $this->getJson(route('projects.apps.index', $project))->assertOk();
    }

    public function test_os_logout_does_not_log_out_the_independent_app(): void
    {
        [$owner, $workspace, $project] = $this->project();
        $app = $this->app($project);
        $this->account($app);
        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id]);
        $this->loginApp($app, 'staff1');
        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
        $this->getJson(route('apps.data.read', $app))->assertOk();
    }

    public function test_password_reset_invalidates_staff_sessions_and_old_password(): void
    {
        [, , $project] = $this->project();
        $app = $this->app($project);
        $staff = $this->account($app);
        $this->account($app, 'admin', 'admin');
        $staffToken = $this->loginApp($app, 'staff1');
        $this->loginApp($app, 'admin');
        $this->put(route('apps.accounts.update', [$app, $staff]), [
            'name' => 'staff1', 'role' => 'staff', 'enabled' => true, 'password' => 'new-test-password',
        ])->assertRedirect();
        $this->withCookie('rg_app_'.$app->public_id, $staffToken)->getJson(route('apps.data.read', $app))->assertUnauthorized();
        $this->post(route('apps.login', $app), ['login' => 'staff1', 'password' => 'test-password'])->assertSessionHasErrors('login');
        $this->post(route('apps.login', $app), ['login' => 'staff1', 'password' => 'new-test-password'])->assertRedirect(route('apps.run', $app));
    }

    public function test_login_attempts_are_limited_per_app(): void
    {
        [, , $project] = $this->project();
        $app = $this->app($project);
        $other = $this->app($project);
        $this->account($app);
        $this->account($other);
        for ($i = 0; $i < 6; $i++) {
            $this->post(route('apps.login', $app), ['login' => 'staff1', 'password' => 'wrong'])->assertSessionHasErrors('login');
        }
        $this->post(route('apps.login', $app), ['login' => 'staff1', 'password' => 'test-password'])->assertStatus(429);
        $this->loginApp($other, 'staff1');
        $this->getJson(route('apps.data.read', $other))->assertOk();
    }

    public function test_empty_json_objects_are_preserved_and_rendered_source_is_escaped(): void
    {
        [, , $project] = $this->project();
        $app = $this->app($project);
        $app->update(['html' => '</script><script>window.hostCompromised=true</script>']);
        $this->account($app);
        $this->loginApp($app, 'staff1');
        $this->putJson(route('apps.data.write', $app), ['revision' => 0, 'data' => ['options' => (object) []]])->assertOk();
        $response = $this->getJson(route('apps.data.read', $app))->assertOk();
        $this->assertIsObject(json_decode($response->getContent())->data->options);
        $this->get(route('apps.run', $app))->assertOk()->assertDontSee('<script>window.hostCompromised=true</script>', false);
        $this->getJson(route('apps.data.read', ['projectApp' => $app, 'account' => ['invalid']]))->assertUnprocessable();
    }

    public function test_oversized_data_is_not_saved(): void
    {
        [, , $project] = $this->project();
        $app = $this->app($project);
        $this->account($app);
        $this->loginApp($app, 'staff1');
        $this->putJson(route('apps.data.write', $app), ['revision' => 0, 'data' => ['text' => str_repeat('x', 500001)]])->assertUnprocessable();
        $this->assertDatabaseCount('project_app_data', 0);
    }
}
