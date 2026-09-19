<?php

namespace App\Http\Controllers\SystemAdmin;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\AccountAudit;
use App\Services\AccountLoginLimiter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): View
    {
        return view('system-admin.auth.login', ['email' => $request->user()?->email]);
    }

    public function store(Request $request, AccountLoginLimiter $limiter, AccountAudit $audit): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $limiter->ensureNotLimited($credentials['email'], $request->ip());
        $credentials['is_active'] = true;
        $credentials['is_system_admin'] = true;

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            $limiter->hit($credentials['email'], $request->ip());
            $audit->record('account.system_admin_login', 'rejected');
            throw ValidationException::withMessages([
                'email' => 'System Adminの認証情報を確認してください。',
            ]);
        }

        $limiter->clearIdentity($credentials['email'], $request->ip());
        $request->session()->regenerate();
        $request->session()->put('access_mode', 'system_admin');
        $request->session()->put('credential_generation', (int) $request->user()->credential_generation);
        $audit->record('account.system_admin_login', 'success', $request->user(), $request->user());

        return redirect()->intended(route('system-admin.members.index'));
    }

    public function exit(Request $request): RedirectResponse
    {
        $request->session()->put('access_mode', 'workspace');
        $workspace = $request->user()->workspaces()
            ->where('workspaces.status', Workspace::STATUS_ACTIVE)
            ->orderBy('workspaces.name')
            ->first();

        if (! $workspace) {
            $request->session()->forget('current_workspace_id');

            return redirect()->route('workspaces.index');
        }

        $request->session()->put('current_workspace_id', $workspace->id);

        return redirect()->route('dashboard');
    }
}
