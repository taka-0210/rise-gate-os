<?php

namespace App\Http\Controllers;

use App\Models\OrganizationGroup;
use App\Models\OrganizationUser;
use App\Services\Organization\OrganizationAccess;
use App\Services\Organization\OrganizationAdministration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrganizationManagementController extends Controller
{
    public function index(Request $request, OrganizationAccess $access): View
    {
        $organization = $request->attributes->get('currentCompany');
        $access->authorizeManage($request->user(), $organization);

        $memberships = OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->with(['user', 'groups' => fn ($query) => $query->orderBy('name')])
            ->orderBy('id')
            ->get();
        $groups = OrganizationGroup::query()
            ->where('organization_id', $organization->id)
            ->with(['memberships.organizationMembership.user'])
            ->orderByRaw('archived_at IS NOT NULL')
            ->orderBy('name')
            ->get();

        return view('organization-management.index', [
            'organization' => $organization,
            'memberships' => $memberships,
            'groups' => $groups,
            'canChangeRoles' => $access->canChangeRoles($request->user(), $organization),
        ]);
    }

    public function updateRole(
        Request $request,
        OrganizationUser $organizationMembership,
        OrganizationAdministration $administration,
    ): RedirectResponse {
        $validated = $request->validate([
            'organization_role' => ['required', Rule::in(array_keys(OrganizationUser::organizationRoles()))],
        ]);
        $administration->updateRole(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $organizationMembership,
            $validated['organization_role'],
        );

        return back()->with('success', 'Organization Roleを更新しました。');
    }

    public function updatePosition(
        Request $request,
        OrganizationUser $organizationMembership,
        OrganizationAdministration $administration,
    ): RedirectResponse {
        $validated = $request->validate(['position' => ['nullable', 'string', 'max:100']]);
        $administration->updatePosition(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $organizationMembership,
            $validated['position'] ?? null,
        );

        return back()->with('success', 'Positionを更新しました。');
    }

    public function storeGroup(Request $request, OrganizationAdministration $administration): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $administration->createGroup(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $validated['name'],
        );

        return back()->with('success', 'Groupを作成しました。');
    }

    public function updateGroup(
        Request $request,
        OrganizationGroup $organizationGroup,
        OrganizationAdministration $administration,
    ): RedirectResponse {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $administration->renameGroup(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $organizationGroup,
            $validated['name'],
        );

        return back()->with('success', 'Group名を更新しました。');
    }

    public function archiveGroup(
        Request $request,
        OrganizationGroup $organizationGroup,
        OrganizationAdministration $administration,
    ): RedirectResponse {
        $administration->archiveGroup(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $organizationGroup,
        );

        return back()->with('success', '空のGroupを保管しました。');
    }

    public function addGroupMember(
        Request $request,
        OrganizationGroup $organizationGroup,
        OrganizationAdministration $administration,
    ): RedirectResponse {
        $validated = $request->validate([
            'organization_user_id' => ['required', 'integer'],
        ]);
        $target = OrganizationUser::query()->findOrFail($validated['organization_user_id']);
        $administration->addGroupMember(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $organizationGroup,
            $target,
        );

        return back()->with('success', 'Groupへ所属を追加しました。');
    }

    public function removeGroupMember(
        Request $request,
        OrganizationGroup $organizationGroup,
        OrganizationUser $organizationMembership,
        OrganizationAdministration $administration,
    ): RedirectResponse {
        $administration->removeGroupMember(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $organizationGroup,
            $organizationMembership,
        );

        return back()->with('success', 'Group所属を解除しました。');
    }
}
