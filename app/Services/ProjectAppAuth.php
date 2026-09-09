<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectApp;
use App\Models\ProjectAppAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectAppAuth
{
    public function account(Request $request, ProjectApp $app): ?ProjectAppAccount
    {
        abort_unless(Project::whereKey($app->project_id)->exists(), 404);
        $token = $request->cookie($this->cookieName($app));
        if (! is_string($token) || strlen($token) !== 64) {
            return null;
        }
        $session = DB::table('project_app_sessions')->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())->first();

        return $session ? ProjectAppAccount::whereKey($session->project_app_account_id)
            ->where('project_app_id', $app->id)->where('enabled', true)->first() : null;
    }

    public function requireAccount(Request $request, ProjectApp $app, bool $admin = false): ProjectAppAccount
    {
        $account = $this->account($request, $app);
        abort_unless($account, 401, 'アプリに再ログインしてください。');
        abort_if($admin && $account->role !== 'admin', 403);

        return $account;
    }

    public function cookieName(ProjectApp $app): string
    {
        return 'rg_app_'.$app->public_id;
    }

    public function cookiePath(Request $request, ProjectApp $app): string
    {
        return $request->getBaseUrl().'/apps/'.$app->public_id;
    }
}
