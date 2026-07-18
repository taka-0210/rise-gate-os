<?php

namespace App\Http\Controllers;

use App\Models\AiAccessKey;
use App\Models\AiAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AiConnectionController extends Controller
{
    public function index(Request $request): View
    {
        $workspace = $request->attributes->get('currentWorkspace');
        $keys = AiAccessKey::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return view('ai-connections.index', [
            'keys' => $keys,
            'mcpUrl' => url('/api/mcp/rise-gate-os'),
            'newToken' => $request->session()->get('new_ai_token'),
            'aiEnabled' => (bool) $workspace->aiSetting?->enabled,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $request->attributes->get('currentWorkspace');
        abort_unless($workspace->aiSetting?->enabled, 403, '先にWorkspaceのAI機能を有効にしてください。');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'days' => ['required', Rule::in([30, 90, 180])],
        ]);
        $plainToken = 'rgos_'.Str::random(64);

        $key = AiAccessKey::create([
            'workspace_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'token_hash' => hash('sha256', $plainToken),
            'scopes' => [AiAccessKey::SCOPE_PROJECTS_READ, AiAccessKey::SCOPE_PROPOSALS_CREATE],
            'expires_at' => now()->addDays((int) $validated['days']),
            'created_by' => $request->user()->id,
        ]);

        AiAuditLog::create([
            'workspace_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'ai_access_key_id' => $key->id,
            'event' => 'connection.created',
            'succeeded' => true,
            'metadata' => ['name' => $key->name, 'expires_at' => $key->expires_at?->toIso8601String()],
            'occurred_at' => now(),
        ]);

        return redirect()->route('ai-connections.index')
            ->with('new_ai_token', $plainToken)
            ->with('status', 'Codex接続を発行しました。接続キーは今回だけ表示されます。');
    }

    public function destroy(Request $request, AiAccessKey $aiAccessKey): RedirectResponse
    {
        $workspace = $request->attributes->get('currentWorkspace');
        abort_unless($aiAccessKey->workspace_id === $workspace->id && $aiAccessKey->user_id === $request->user()->id, 404);

        if (! $aiAccessKey->revoked_at) {
            $aiAccessKey->update(['revoked_at' => now()]);
            AiAuditLog::create([
                'workspace_id' => $workspace->id,
                'user_id' => $request->user()->id,
                'ai_access_key_id' => $aiAccessKey->id,
                'event' => 'connection.revoked',
                'succeeded' => true,
                'occurred_at' => now(),
            ]);
        }

        return redirect()->route('ai-connections.index')->with('status', 'AI接続を停止しました。');
    }
}
