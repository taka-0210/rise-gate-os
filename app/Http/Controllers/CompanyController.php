<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Services\Organization\OrganizationSessionContext;
use App\Services\ProductOrganization\ProductOrganizationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyController extends Controller
{
    public function index(
        Request $request,
        OrganizationSessionContext $sessionContext,
        ProductOrganizationResolver $productOrganizations,
    ): View|RedirectResponse {
        $resolved = $productOrganizations->resolve($request->user());
        $companies = $resolved['organizations'];
        if ($companies->isNotEmpty()) {
            $workspaceCounts = Organization::query()
                ->whereIn('id', $companies->pluck('id'))
                ->withCount('workspaces')
                ->get()
                ->keyBy('id');
            $companies->each(function (Organization $company) use ($workspaceCounts): void {
                $company->setAttribute('workspaces_count', $workspaceCounts->get($company->id)?->workspaces_count ?? 0);
            });
        }

        if ($resolved['state'] === 'ready') {
            $membership = $resolved['membership'] ?? OrganizationUser::query()
                ->where('organization_id', $resolved['organization']->id)
                ->where('user_id', $request->user()->id)
                ->firstOrFail();
            $sessionContext->select($request, $membership);

            return redirect()->route('company.home');
        }

        return view('companies.index', [
            'companies' => $companies,
            'productOrganizationState' => $resolved['state'],
            'productOrganizationMode' => $resolved['mode'],
        ]);
    }

    public function switch(
        Request $request,
        Organization $organization,
        OrganizationSessionContext $sessionContext,
        ProductOrganizationResolver $productOrganizations,
    ): RedirectResponse {
        abort_unless($productOrganizations->canExplicitlySwitch($request->user(), $organization->id), 403);

        $membership = OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $request->user()->id)
            ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->firstOrFail();
        $sessionContext->select($request, $membership);

        return redirect()->route('company.home');
    }
}
