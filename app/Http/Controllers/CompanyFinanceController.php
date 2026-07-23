<?php

namespace App\Http\Controllers;

use App\Models\CompanyFinancialPeriod;
use App\Models\OrganizationUser;
use App\Services\Company\CompanyAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyFinanceController extends Controller
{
    public function index(Request $request, CompanyAccess $access): View
    {
        $organization = $request->attributes->get('currentCompany');
        abort_unless(
            $access->allows($request->user(), $organization, OrganizationUser::PERMISSION_FINANCE_VIEW_PL),
            403
        );
        $periods = CompanyFinancialPeriod::query()
            ->where('organization_id', $organization->id)
            ->where('status', CompanyFinancialPeriod::STATUS_ACTUAL)
            ->orderByDesc('fiscal_year')
            ->get();

        return view('company-finance.index', compact('organization', 'periods'));
    }
}
