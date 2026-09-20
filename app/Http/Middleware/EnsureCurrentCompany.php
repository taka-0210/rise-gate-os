<?php

namespace App\Http\Middleware;

use App\Models\OrganizationUser;
use App\Services\Company\CompanyAccess;
use App\Services\Organization\OrganizationAccess;
use App\Services\Organization\OrganizationSessionContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentCompany
{
    public function __construct(
        private readonly CompanyAccess $companyAccess,
        private readonly OrganizationAccess $organizationAccess,
        private readonly OrganizationSessionContext $sessionContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $companies = $user->organizations()
            ->wherePivot('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->orderBy('organizations.name')
            ->get();

        if ($companies->isEmpty()) {
            $this->sessionContext->clear($request);

            return redirect()->route('companies.index');
        }

        $companyId = (int) $request->session()->get('current_company_id');
        $company = $companies->firstWhere('id', $companyId);

        if ($company) {
            $membershipEpoch = (int) $company->pivot->access_epoch;
            $sessionEpoch = $request->session()->get(OrganizationSessionContext::ACCESS_EPOCH);
            $legacyEpochMayBeSeeded = $sessionEpoch === null && $membershipEpoch === 1;
            if ($legacyEpochMayBeSeeded) {
                $request->session()->put(OrganizationSessionContext::ACCESS_EPOCH, $membershipEpoch);
            } elseif ((int) $sessionEpoch !== $membershipEpoch) {
                $this->sessionContext->clear($request);

                return redirect()->route('companies.index')
                    ->with('status', '所属状態が更新されました。利用する会社を選び直してください。');
            }
        }

        if (! $company) {
            if ($companies->count() > 1) {
                $this->sessionContext->clear($request);

                return redirect()->route('companies.index');
            }

            $company = $companies->first();
            $membership = OrganizationUser::query()
                ->where('organization_id', $company->id)
                ->where('user_id', $user->id)
                ->firstOrFail();
            $this->sessionContext->select($request, $membership);
        }

        $request->attributes->set('currentCompany', $company);
        View::share('currentCompany', $company);
        View::share('availableCompanyCount', $companies->count());
        View::share(
            'canViewCompanyFinance',
            $this->companyAccess->allows($user, $company, OrganizationUser::PERMISSION_FINANCE_VIEW_PL)
        );
        View::share(
            'canManageCompanyMembers',
            $this->companyAccess->canManageMembers($user, $company)
        );
        View::share('canManageOrganization', $this->organizationAccess->canManage($user, $company));
        View::share(
            'canViewCompanyDebt',
            $this->companyAccess->allows($user, $company, OrganizationUser::PERMISSION_FINANCE_VIEW_DEBT)
        );

        return $next($request);
    }
}
