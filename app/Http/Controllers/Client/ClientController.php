<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $currentWorkspace = $request->attributes->get('currentWorkspace');

        $clients = Client::query()
            ->where('workspace_id', $currentWorkspace->id)
            ->withCount('projects')
            ->orderBy('name')
            ->paginate(12);

        return view('clients.index', [
            'clients' => $clients,
        ]);
    }

    public function create(Request $request): View
    {
        $currentWorkspace = $request->attributes->get('currentWorkspace');

        Gate::authorize('create', [Client::class, $currentWorkspace]);

        return view('clients.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $currentWorkspace = $request->attributes->get('currentWorkspace');

        Gate::authorize('create', [Client::class, $currentWorkspace]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'kana' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'url', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'memo' => ['nullable', 'string', 'max:5000'],
        ]);

        $client = Client::create($validated + [
            'organization_id' => $currentWorkspace->organization_id,
            'workspace_id' => $currentWorkspace->id,
        ]);

        return redirect()->route('clients.show', $client);
    }

    public function show(Request $request, Client $client): View
    {
        $currentWorkspace = $request->attributes->get('currentWorkspace');

        abort_unless($client->workspace_id === $currentWorkspace->id, 404);
        Gate::authorize('view', $client);

        return view('clients.show', [
            'client' => $client,
            'projectsCount' => $client->projects()->count(),
        ]);
    }
}
