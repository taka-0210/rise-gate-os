<?php

namespace App\Http\Controllers;

use App\Models\BusinessDomain;
use App\Models\CompanyFinancialPeriod;
use App\Models\CompanyLoan;
use App\Models\CompanyObservation;
use App\Models\Workspace;
use App\Models\Project;
use App\Services\BusinessDomain\BusinessDomainAccess;
use App\Services\ProjectExecution\ProjectExecutionAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyHomeController extends Controller
{
    public function __invoke(Request $request, BusinessDomainAccess $businessDomainAccess, ProjectExecutionAccess $projectExecutionAccess): View
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
            'personalWorkspaceCreationEnabled' => (bool) $company->personal_workspace_creation_enabled,
            'financialPeriodCount' => CompanyFinancialPeriod::query()
                ->where('organization_id', $company->id)
                ->where('status', CompanyFinancialPeriod::STATUS_ACTUAL)
                ->count(),
            'loanBalance' => CompanyLoan::query()
                ->where('organization_id', $company->id)
                ->where('loan_status', CompanyLoan::STATUS_ACTIVE)
                ->sum('current_balance'),
            'observationCount' => CompanyObservation::query()
                ->where('organization_id', $company->id)
                ->count(),
            'unreviewedObservationCount' => CompanyObservation::query()
                ->where('organization_id', $company->id)
                ->where('importance', CompanyObservation::IMPORTANCE_UNREVIEWED)
                ->count(),
            'businessDomainCount' => BusinessDomain::query()
                ->where('organization_id', $company->id)
                ->where('status', BusinessDomain::STATUS_ACTIVE)
                ->count(),
            'executionProjectCount' => Project::query()
                ->where('organization_id', $company->id)
                ->where('execution_contract_version', Project::EXECUTION_CONTRACT)
                ->get()->filter(fn (Project $project) => $projectExecutionAccess->canRead($request->user(), $project))->count(),
            'canEditBusinessDomains' => $businessDomainAccess->canEdit($request->user(), $company),
        ]);
    }
}
