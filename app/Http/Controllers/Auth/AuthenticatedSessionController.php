<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\OrganizationUser;
use App\Services\AccountAudit;
use App\Services\AccountLoginLimiter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request, AccountLoginLimiter $limiter, AccountAudit $audit): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $limiter->ensureNotLimited($credentials['email'], $request->ip());
        $credentials['is_active'] = true;

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            $limiter->hit($credentials['email'], $request->ip());
            $audit->record('account.login', 'rejected');
            throw ValidationException::withMessages([
                'email' => 'メールアドレスまたはパスワードが正しくありません。',
            ]);
        }

        $limiter->clearIdentity($credentials['email'], $request->ip());
        $request->session()->regenerate();
        $request->session()->put('access_mode', 'workspace');
        $request->session()->put('credential_generation', (int) $request->user()->credential_generation);
        $request->session()->forget('url.intended');
        $audit->record('account.login', 'success', $request->user(), $request->user());

        $companies = $request->user()->organizations()
            ->wherePivot('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->orderBy('organizations.name')
            ->get();
        if ($companies->count() === 1) {
            $request->session()->put('current_company_id', $companies->first()->id);
            $request->session()->forget('current_workspace_id');

            return redirect()->route('company.home');
        }

        $request->session()->forget(['current_company_id', 'current_workspace_id']);

        return redirect()->route('companies.index');
    }

    public function destroy(Request $request): RedirectResponse
    {
        app(AccountAudit::class)->record('account.logout', 'success', $request->user(), $request->user());
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('welcome');
    }
}
