<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Workspace;
use App\Services\Company\PromoteClientToCompanyAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class ClientCompanyAccountController extends Controller
{
    public function store(
        Request $request,
        Client $client,
        PromoteClientToCompanyAccount $promoter,
    ): RedirectResponse {
        $currentWorkspace = $request->attributes->get('currentWorkspace');
        abort_unless($client->workspace_id === $currentWorkspace->id, 404);
        Gate::authorize('create', [Client::class, $currentWorkspace]);

        $validated = $request->validate([
            'workspace_name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $workspace = $promoter->promote($client, $request->user(), $validated['workspace_name']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['company_account' => $exception->getMessage()]);
        }

        if ($workspace->status === Workspace::STATUS_ACTIVE) {
            $request->session()->put('current_company_id', $workspace->organization_id);
            $request->session()->put('current_workspace_id', $workspace->id);

            return redirect()
                ->route('company-finance.index')
                ->with('status', "{$client->name}の会社アカウントと経営Workspaceを作成しました。");
        }

        return redirect()
            ->route('workspaces.index')
            ->with('status', "{$client->name}の会社アカウントを作成しました。Workspaceは承認待ちです。");
    }
}
