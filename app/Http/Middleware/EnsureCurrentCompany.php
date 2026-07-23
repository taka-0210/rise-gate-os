<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Services\Company\CompanyAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentCompany
{
    public function __construct(private readonly CompanyAccess $companyAccess)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $companies = $user->organizations()->orderBy('organizations.name')->get();

        if ($companies->isEmpty()) {
            $request->session()->forget(['current_company_id', 'current_workspace_id']);

            return redirect()->route('companies.index');
        }

        $companyId = (int) $request->session()->get('current_company_id');
        $company = $companies->firstWhere('id', $companyId);

        if (! $company) {
            if ($companies->count() > 1) {
                $request->session()->forget(['current_company_id', 'current_workspace_id']);

                return redirect()->route('companies.index');
            }

            $company = $companies->first();
            $request->session()->put('current_company_id', $company->id);
            $request->session()->forget('current_workspace_id');
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
        View::share(
            'canViewCompanyDebt',
            $this->companyAccess->allows($user, $company, OrganizationUser::PERMISSION_FINANCE_VIEW_DEBT)
        );

        return $next($request);
    }
}
