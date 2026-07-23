<?php

namespace App\Http\Middleware;

use App\Models\OrganizationUser;
use App\Models\Workspace;
use App\Services\Company\CompanyAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentWorkspace
{
    public function __construct(private readonly CompanyAccess $companyAccess)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $currentCompany = $request->attributes->get('currentCompany');
        $workspaceId = $request->session()->get('current_workspace_id');

        if (! $user) {
            return redirect()->route('login');
        }

        $selectedWorkspaceIsValid = $workspaceId
            && $user->canAccessWorkspace((int) $workspaceId)
            && Workspace::query()
                ->whereKey($workspaceId)
                ->where('organization_id', $currentCompany->id)
                ->exists();

        if (! $selectedWorkspaceIsValid) {
            $workspace = $user->workspaces()
                ->where('workspaces.status', Workspace::STATUS_ACTIVE)
                ->where('workspaces.organization_id', $currentCompany->id)
                ->orderBy('workspaces.name')
                ->first();

            if (! $workspace) {
                $request->session()->forget('current_workspace_id');
                return redirect()->route('company.home');
            }

            $workspaceId = $workspace->id;
            $request->session()->put('current_workspace_id', $workspaceId);
        }

        $currentWorkspace = Workspace::query()
            ->with('organization')
            ->where('organization_id', $currentCompany->id)
            ->findOrFail($workspaceId);

        $membership = $user->workspaces()
            ->where('workspaces.id', $currentWorkspace->id)
            ->firstOrFail()
            ->pivot;

        $request->attributes->set('currentWorkspace', $currentWorkspace);
        $request->attributes->set('currentWorkspaceRole', $membership->role);

        View::share('currentWorkspace', $currentWorkspace);
        View::share('currentWorkspaceRole', $membership->role);
        View::share(
            'canViewCompanyFinance',
            $this->companyAccess->allows($user, $currentWorkspace->organization, OrganizationUser::PERMISSION_FINANCE_VIEW_PL)
        );
        View::share(
            'canManageCompanyMembers',
            $this->companyAccess->canManageMembers($user, $currentWorkspace->organization)
        );

        return $next($request);
    }
}
