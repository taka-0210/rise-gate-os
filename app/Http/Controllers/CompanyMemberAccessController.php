<?php

namespace App\Http\Controllers;

use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\Company\CompanyAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CompanyMemberAccessController extends Controller
{
    public function index(Request $request, CompanyAccess $access): View
    {
        $organization = $request->attributes->get('currentCompany');
        abort_unless($access->canManageMembers($request->user(), $organization), 403);

        $memberships = OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->with('user')
            ->orderBy('id')
            ->get();

        return view('company-members.index', compact('organization', 'memberships'));
    }

    public function update(
        Request $request,
        User $user,
        CompanyAccess $access,
    ): RedirectResponse {
        $organization = $request->attributes->get('currentCompany');
        abort_unless($access->canManageMembers($request->user(), $organization), 403);

        $membership = OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $validated = $request->validate([
            'company_role' => ['required', Rule::in(array_keys(OrganizationUser::companyRoles()))],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::in(array_keys(OrganizationUser::permissionLabels()))],
        ]);

        $membership->update([
            'company_role' => $validated['company_role'],
            'permissions' => array_values(array_unique($validated['permissions'] ?? [])),
        ]);

        return back()->with('success', "{$user->name}さんの会社権限を更新しました。");
    }
}
