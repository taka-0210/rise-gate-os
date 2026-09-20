<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Services\Organization\OrganizationSessionContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyController extends Controller
{
    public function index(Request $request, OrganizationSessionContext $sessionContext): View|RedirectResponse
    {
        $companies = $request->user()
            ->organizations()
            ->wherePivot('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->withCount('workspaces')
            ->orderBy('organizations.name')
            ->get();

        if ($companies->count() === 1) {
            $membership = OrganizationUser::query()
                ->where('organization_id', $companies->first()->id)
                ->where('user_id', $request->user()->id)
                ->firstOrFail();
            $sessionContext->select($request, $membership);

            return redirect()->route('company.home');
        }

        return view('companies.index', compact('companies'));
    }

    public function switch(
        Request $request,
        Organization $organization,
        OrganizationSessionContext $sessionContext,
    ): RedirectResponse {
        abort_unless(
            $request->user()->organizations()
                ->wherePivot('membership_status', OrganizationUser::STATUS_ACTIVE)
                ->where('organizations.id', $organization->id)
                ->exists(),
            403
        );

        $membership = OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $request->user()->id)
            ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->firstOrFail();
        $sessionContext->select($request, $membership);

        return redirect()->route('company.home');
    }
}
