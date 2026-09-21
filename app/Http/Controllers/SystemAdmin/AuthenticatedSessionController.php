<?php

namespace App\Http\Controllers\SystemAdmin;

use App\Http\Controllers\Controller;
use App\Models\OrganizationUser;
use App\Services\AccountAudit;
use App\Services\AccountLoginLimiter;
use App\Services\Organization\OrganizationSessionContext;
use App\Services\ProductOrganization\ProductOrganizationResolver;
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

    public function exit(
        Request $request,
        ProductOrganizationResolver $productOrganizations,
        OrganizationSessionContext $sessionContext,
    ): RedirectResponse {
        $request->session()->put('access_mode', 'workspace');
        $resolved = $productOrganizations->resolve($request->user());
        if ($resolved['state'] === 'ready') {
            $membership = $resolved['membership'] ?? OrganizationUser::query()
                ->where('user_id', $request->user()->id)
                ->where('organization_id', $resolved['organization']->id)
                ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
                ->firstOrFail();
            $sessionContext->select($request, $membership);

            return redirect()->route('company.home');
        }
        if ($resolved['state'] === 'selection') {
            $currentCompanyId = (int) $request->session()->get(OrganizationSessionContext::COMPANY_ID);
            $membership = $currentCompanyId > 0
                ? $productOrganizations->activeMembershipFor($request->user(), $currentCompanyId)
                : null;
            if ($membership) {
                $sessionContext->select($request, $membership);

                return redirect()->route('company.home');
            }
        }
        $sessionContext->clear($request);

        return redirect()->route('companies.index');
    }
}
