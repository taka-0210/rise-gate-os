<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;

class ClientPolicy
{
    public function create(User $user, Workspace $workspace): bool
    {
        $role = $user->workspaces()
            ->where('workspaces.id', $workspace->id)
            ->first()?->pivot?->role;

        return in_array($role, ['owner', 'admin', 'member'], true);
    }

    public function view(User $user, Client $client): bool
    {
        return $user->canAccessWorkspace($client->workspace_id);
    }
}
