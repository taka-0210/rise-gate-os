<?php

namespace App\Http\Controllers;

use App\Models\OrganizationGroup;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationUser;
use App\Services\Organization\OrganizationAccess;
use App\Services\Organization\OrganizationAdministration;
use App\Services\Organization\OrganizationMembershipLifecycle;
use App\Services\Organization\StandardWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrganizationManagementController extends Controller
{
    public function index(
        Request $request,
        OrganizationAccess $access,
        OrganizationMembershipLifecycle $lifecycle,
    ): View {
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

        $actorMembership = $access->membership($request->user(), $organization);
        $lifecycleActions = $memberships->mapWithKeys(function (OrganizationUser $membership) use (
            $actorMembership,
            $lifecycle,
        ): array {
            $commands = $actorMembership ? $lifecycle->availableCommands($actorMembership, $membership) : [];

            return [$membership->id => collect($commands)->mapWithKeys(
                fn (string $command): array => [$command => (string) Str::uuid()],
            )->all()];
        })->all();

        return view('organization-management.index', [
            'organization' => $organization,
            'memberships' => $memberships,
            'groups' => $groups,
            'canChangeRoles' => $access->canChangeRoles($request->user(), $organization),
            'invitations' => OrganizationInvitation::query()
                ->where('organization_id', $organization->id)
                ->with(['groups', 'sponsor'])
                ->latest('id')
                ->get(),
            'actorMembership' => $actorMembership,
            'lifecycleActions' => $lifecycleActions,
            'standardWorkspace' => $organization->standardWorkspace,
        ]);
    }

    public function initializeStandardWorkspace(
        Request $request,
        StandardWorkspaceService $workspaces,
    ): RedirectResponse {
        $workspaces->initialize(
            $request->user(),
            $request->attributes->get('currentCompany'),
        );

        return back()->with('success', '空の標準Workspaceを初期設定しました。既存Workspaceと既存所属は変更していません。');
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

    public function updateMembershipLifecycle(
        Request $request,
        OrganizationUser $organizationMembership,
        OrganizationMembershipLifecycle $lifecycle,
    ): RedirectResponse {
        $validated = $request->validate([
            'command' => ['required', Rule::in([
                OrganizationMembershipLifecycle::COMMAND_SUSPEND,
                OrganizationMembershipLifecycle::COMMAND_END,
                OrganizationMembershipLifecycle::COMMAND_RESUME,
            ])],
            'reason' => ['required', 'string', 'max:500'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'request_id' => ['required', 'string', 'max:64'],
        ]);
        $lifecycle->execute(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $organizationMembership,
            $validated['command'],
            $validated['reason'],
            (int) $validated['expected_version'],
            $validated['request_id'],
        );

        return back()->with('success', match ($validated['command']) {
            OrganizationMembershipLifecycle::COMMAND_SUSPEND => 'Organization所属を一時停止しました。',
            OrganizationMembershipLifecycle::COMMAND_END => 'Organization所属を終了しました。',
            OrganizationMembershipLifecycle::COMMAND_RESUME => 'Organization所属を再開しました。',
        });
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
