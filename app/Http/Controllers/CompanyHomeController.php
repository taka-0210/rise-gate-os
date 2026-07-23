<?php

namespace App\Http\Controllers;

use App\Models\CompanyFinancialPeriod;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyHomeController extends Controller
{
    public function __invoke(Request $request): View
    {
        $company = $request->attributes->get('currentCompany');
        $workspaces = $request->user()
            ->workspaces()
            ->where('workspaces.organization_id', $company->id)
            ->withCount(['projects', 'improvements'])
            ->orderByRaw("CASE WHEN type = 'shared' THEN 0 ELSE 1 END")
            ->orderBy('workspaces.name')
            ->get();

        return view('companies.home', [
            'company' => $company,
            'sharedWorkspaces' => $workspaces->where('type', Workspace::TYPE_SHARED),
            'personalWorkspaces' => $workspaces->where('type', Workspace::TYPE_PERSONAL),
            'financialPeriodCount' => CompanyFinancialPeriod::query()
                ->where('organization_id', $company->id)
                ->where('status', CompanyFinancialPeriod::STATUS_ACTUAL)
                ->count(),
        ]);
    }
}
