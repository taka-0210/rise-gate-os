<?php

namespace App\Http\Controllers;

use App\Models\ProjectApp;
use App\Models\ProjectAppAccount;
use App\Services\ProjectAppAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StandaloneAppController extends Controller
{
    public function __construct(private readonly ProjectAppAuth $auth) {}

    public function run(Request $request, ProjectApp $projectApp)
    {
        $account = $this->auth->account($request, $projectApp);
        if (! $account) {
            return response()->view('project-apps.login', ['app' => $projectApp])->header('Cache-Control', 'no-store');
        }
        $target = $this->dataAccount($request, $projectApp, $account);
        $accounts = $account->role === 'admin'
            ? ProjectAppAccount::where('project_app_id', $projectApp->id)->where('enabled', true)->get() : collect();

        return response()->view('project-apps.run', [
            'app' => $projectApp, 'account' => $account, 'targetAccount' => $target, 'accounts' => $accounts,
        ])->header('Cache-Control', 'no-store');
    }

    public function login(Request $request, ProjectApp $projectApp)
    {
        // Check that the parent project still exists, without depending on OS authentication.
        $this->auth->account($request, $projectApp);
        $input = $request->validate(['login' => ['required', 'string', 'max:80'], 'password' => ['required', 'string', 'max:200']]);
        $account = ProjectAppAccount::where('project_app_id', $projectApp->id)
            ->where('login', strtolower($input['login']))->where('enabled', true)->first();
        if (! $account || ! Hash::check($input['password'], $account->password)) {
            return back()->withErrors(['login' => 'ログインIDまたはパスワードを確認してください。']);
        }
        $old = $request->cookie($this->auth->cookieName($projectApp));
        if (is_string($old)) {
            DB::table('project_app_sessions')->where('token_hash', hash('sha256', $old))->delete();
        }
        DB::table('project_app_sessions')->where('project_app_account_id', $account->id)->where('expires_at', '<=', now())->delete();
        $token = Str::random(64);
        DB::table('project_app_sessions')->insert([
            'project_app_account_id' => $account->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7),
        ]);

        return redirect()->route('apps.run', $projectApp)->withCookie(cookie(
            $this->auth->cookieName($projectApp), $token, 60 * 24 * 7,
            $this->auth->cookiePath($request, $projectApp), null, $request->isSecure(), true, false, 'lax',
        ));
    }

    public function logout(Request $request, ProjectApp $projectApp)
    {
        $token = $request->cookie($this->auth->cookieName($projectApp));
        if (is_string($token)) {
            DB::table('project_app_sessions')->where('token_hash', hash('sha256', $token))->delete();
        }

        return redirect()->route('apps.run', $projectApp)->withCookie(cookie(
            $this->auth->cookieName($projectApp), '', -1, $this->auth->cookiePath($request, $projectApp),
        ));
    }

    public function accounts(Request $request, ProjectApp $projectApp)
    {
        $account = $this->auth->requireAccount($request, $projectApp, true);

        return response()->view('project-apps.accounts', [
            'app' => $projectApp, 'account' => $account,
            'accounts' => ProjectAppAccount::where('project_app_id', $projectApp->id)->orderBy('id')->get(),
        ])->header('Cache-Control', 'no-store');
    }

    public function storeAccount(Request $request, ProjectApp $projectApp)
    {
        $this->auth->requireAccount($request, $projectApp, true);
        $input = $request->validate([
            'login' => ['required', 'string', 'regex:/^[a-z0-9_.-]+$/', 'max:80',
                Rule::unique('project_app_accounts', 'login')->where('project_app_id', $projectApp->id)],
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:8', 'max:200'],
            'role' => ['required', 'in:admin,staff'],
        ]);
        ProjectAppAccount::create([...$input, 'project_app_id' => $projectApp->id, 'enabled' => true]);

        return back()->with('status', 'アカウントを作成しました。');
    }

    public function updateAccount(Request $request, ProjectApp $projectApp, ProjectAppAccount $appAccount)
    {
        $actor = $this->auth->requireAccount($request, $projectApp, true);
        abort_unless($appAccount->project_app_id === $projectApp->id, 404);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'password' => ['nullable', 'string', 'min:8', 'max:200'],
            'role' => ['required', 'in:admin,staff'], 'enabled' => ['required', 'boolean'],
        ]);
        abort_if($actor->id === $appAccount->id && ($input['role'] !== 'admin' || ! $input['enabled']), 422, '自分自身の管理者権限を解除・停止することはできません。');
        if (empty($input['password'])) {
            unset($input['password']);
        }
        $appAccount->update($input);
        DB::table('project_app_sessions')->where('project_app_account_id', $appAccount->id)->delete();

        return back()->with('status', '更新しました。対象アカウントは再ログインが必要です。');
    }

    private function dataAccount(Request $request, ProjectApp $app, ProjectAppAccount $account): ProjectAppAccount
    {
        $target = $request->query('account');
        abort_if($target !== null && (! is_scalar($target) || ! ctype_digit((string) $target)), 422, '対象アカウントを確認してください。');
        if ($target === null || (string) $target === (string) $account->id) {
            return $account;
        }
        abort_unless($account->role === 'admin', 403);

        return ProjectAppAccount::where('project_app_id', $app->id)->where('enabled', true)->findOrFail($target);
    }

    public function readData(Request $request, ProjectApp $projectApp)
    {
        $actor = $this->auth->requireAccount($request, $projectApp);
        $account = $this->dataAccount($request, $projectApp, $actor);
        $row = DB::table('project_app_data')->where('project_app_id', $projectApp->id)
            ->where('project_app_account_id', $account->id)->first();

        return response()->json([
            'data' => $row ? json_decode($row->data) : null, 'revision' => $row?->revision ?? 0,
            'user' => ['id' => $account->id, 'name' => $account->name, 'role' => $account->role],
        ])->header('Cache-Control', 'no-store');
    }

    public function writeData(Request $request, ProjectApp $projectApp)
    {
        $actor = $this->auth->requireAccount($request, $projectApp);
        $account = $this->dataAccount($request, $projectApp, $actor);
        $input = $request->validate([
            'data' => ['present', 'array'], 'revision' => ['required', 'integer', 'min:0'],
            'user_id' => ['prohibited'], 'project_app_id' => ['prohibited'], 'project_app_account_id' => ['prohibited'],
        ]);
        abort_unless($request->isJson(), 415, 'JSONで送信してください。');
        $raw = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $data = json_encode($raw->data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        abort_if(strlen($data) > 500_000, 422, '保存データは500KB以内にしてください。');
        $revision = DB::transaction(function () use ($projectApp, $account, $input, $data): int {
            ProjectApp::whereKey($projectApp->id)->lockForUpdate()->firstOrFail();
            $query = DB::table('project_app_data')->where('project_app_id', $projectApp->id)->where('project_app_account_id', $account->id);
            $row = $query->first();
            abort_unless((int) ($row?->revision ?? 0) === (int) $input['revision'], 409, '別の画面でデータが更新されています。再読込してから操作してください。');
            $next = (int) $input['revision'] + 1;
            if ($row) {
                $query->update(['data' => $data, 'revision' => $next, 'updated_at' => now()]);
            } else {
                DB::table('project_app_data')->insert([
                    'project_app_id' => $projectApp->id, 'project_app_account_id' => $account->id,
                    'data' => $data, 'revision' => $next, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $next;
        });

        return response()->json(['revision' => $revision, 'saved_at' => now()->timezone('Asia/Tokyo')->toIso8601String()]);
    }
}
