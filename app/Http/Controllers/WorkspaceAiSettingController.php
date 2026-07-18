<?php

namespace App\Http\Controllers;

use App\Models\AiAuditLog;
use App\Models\WorkspaceAiSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceAiSettingController extends Controller
{
    public function edit(Request $request): View
    {
        $workspace = $request->attributes->get('currentWorkspace');
        $setting = $workspace->aiSetting()->firstOrCreate([], [
            'enabled' => false,
            'provider' => 'member_managed_ai',
        ]);

        return view('ai-settings.edit', [
            'setting' => $setting,
            'canManage' => in_array($request->attributes->get('currentWorkspaceRole'), ['owner', 'admin'], true),
            'dataCategories' => WorkspaceAiSetting::DEFAULT_DATA_CATEGORIES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->attributes->get('currentWorkspaceRole'), ['owner', 'admin'], true), 403);
        $workspace = $request->attributes->get('currentWorkspace');
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'consent' => ['nullable', 'accepted_if:enabled,1'],
        ]);
        $enabled = (bool) $validated['enabled'];
        $setting = $workspace->aiSetting()->firstOrNew();
        $setting->fill([
            'enabled' => $enabled,
            'provider' => 'member_managed_ai',
            'allowed_data_categories' => WorkspaceAiSetting::DEFAULT_DATA_CATEGORIES,
            'terms_version' => WorkspaceAiSetting::TERMS_VERSION,
            'enabled_by' => $enabled ? $request->user()->id : $setting->enabled_by,
            'enabled_at' => $enabled ? now() : $setting->enabled_at,
            'disabled_by' => $enabled ? null : $request->user()->id,
            'disabled_at' => $enabled ? null : now(),
        ])->save();

        AiAuditLog::create([
            'workspace_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'event' => $enabled ? 'workspace_ai.enabled' : 'workspace_ai.disabled',
            'succeeded' => true,
            'metadata' => ['terms_version' => WorkspaceAiSetting::TERMS_VERSION],
            'occurred_at' => now(),
        ]);

        return redirect()->route('ai-settings.edit')->with('status', $enabled ? 'AI機能を有効にしました。' : 'AI機能を停止しました。');
    }
}
