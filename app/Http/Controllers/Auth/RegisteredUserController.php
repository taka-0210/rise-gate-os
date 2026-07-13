<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'organization_name' => ['required', 'string', 'max:255'],
            'workspace_name' => ['required', 'string', 'max:255'],
        ]);

        [$user, $workspace] = DB::transaction(function () use ($validated): array {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            $organization = Organization::create([
                'name' => $validated['organization_name'],
                'slug' => $this->uniqueOrganizationSlug($validated['organization_name']),
            ]);

            $workspace = Workspace::create([
                'organization_id' => $organization->id,
                'name' => $validated['workspace_name'],
                'slug' => $this->uniqueWorkspaceSlug($organization, $validated['workspace_name']),
            ]);

            $organization->users()->attach($user->id, [
                'role' => OrganizationUser::ROLE_OWNER,
                'joined_at' => now(),
            ]);

            $workspace->users()->attach($user->id, [
                'role' => WorkspaceMember::ROLE_OWNER,
                'joined_at' => now(),
            ]);

            return [$user, $workspace];
        });

        Auth::login($user);
        $request->session()->put('current_workspace_id', $workspace->id);

        return redirect()->route('dashboard');
    }

    private function uniqueOrganizationSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;
        $index = 2;

        while (Organization::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$index;
            $index++;
        }

        return $slug;
    }

    private function uniqueWorkspaceSlug(Organization $organization, string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $index = 2;

        while ($organization->workspaces()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$index;
            $index++;
        }

        return $slug;
    }
}
