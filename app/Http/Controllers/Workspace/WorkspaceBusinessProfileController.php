<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\WorkspaceBankAccount;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkspaceBusinessProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $workspace = $request->attributes->get('currentWorkspace');

        return view('workspaces.business-profile', [
            'workspace' => $workspace,
            'profile' => $workspace->businessProfile,
            'bankAccount' => $workspace->bankAccounts()->first(),
            'accountTypes' => WorkspaceBankAccount::types(),
            'canManage' => $this->canManage($request),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($this->canManage($request), 403);
        $workspace = $request->attributes->get('currentWorkspace');
        $validated = $request->validate([
            'legal_name' => ['nullable', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'representative_title' => ['nullable', 'string', 'max:255'],
            'representative_name' => ['nullable', 'string', 'max:255'],
            'invoice_registration_number' => ['nullable', 'regex:/^T?[0-9]{13}$/'],
            'document_note' => ['nullable', 'string', 'max:2000'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'seal' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_seal' => ['nullable', 'boolean'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'account_type' => ['nullable', Rule::in(array_keys(WorkspaceBankAccount::types()))],
            'account_number' => ['nullable', 'string', 'max:30'],
            'account_holder' => ['nullable', 'string', 'max:255'],
        ], [
            'invoice_registration_number.regex' => '適格登録番号はT＋13桁、または13桁で入力してください。',
        ]);

        DB::transaction(function () use ($request, $workspace, $validated): void {
            $profile = $workspace->businessProfile()->firstOrNew();
            $profile->fill(collect($validated)->except([
                'logo', 'seal', 'remove_logo', 'remove_seal', 'bank_name', 'branch_name',
                'account_type', 'account_number', 'account_holder',
            ])->all());

            foreach (['logo', 'seal'] as $type) {
                $pathField = $type.'_path';
                $nameField = $type.'_original_name';
                if ($request->boolean('remove_'.$type) && $profile->{$pathField}) {
                    Storage::disk('local')->delete($profile->{$pathField});
                    $profile->{$pathField} = null;
                    $profile->{$nameField} = null;
                }
                if ($request->hasFile($type)) {
                    if ($profile->{$pathField}) Storage::disk('local')->delete($profile->{$pathField});
                    $profile->{$pathField} = $request->file($type)->store("workspace-business/{$workspace->id}/{$type}", 'local');
                    $profile->{$nameField} = $request->file($type)->getClientOriginalName();
                }
            }
            $profile->save();

            $bankValues = collect($validated)->only(['bank_name', 'branch_name', 'account_type', 'account_number', 'account_holder']);
            if ($bankValues->filter()->isNotEmpty()) {
                $request->validate([
                    'bank_name' => ['required', 'string', 'max:255'],
                    'account_type' => ['required', Rule::in(array_keys(WorkspaceBankAccount::types()))],
                    'account_number' => ['required', 'string', 'max:30'],
                    'account_holder' => ['required', 'string', 'max:255'],
                ]);
                $account = $workspace->bankAccounts()->firstOrNew(['is_default' => true]);
                $account->fill($bankValues->all() + ['is_default' => true, 'sort_order' => 0])->save();
            }
        });

        return back()->with('status', 'Workspaceの事業者情報を保存しました。');
    }

    public function media(Request $request, string $type): StreamedResponse
    {
        abort_unless(in_array($type, ['logo', 'seal'], true), 404);
        $workspace = $request->attributes->get('currentWorkspace');
        $profile = $workspace->businessProfile;
        $path = $profile?->{$type.'_path'};
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $profile->{$type.'_original_name'}, [
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function projectMedia(Project $project, string $type): StreamedResponse
    {
        Gate::authorize('view', $project);
        abort_unless(in_array($type, ['logo', 'seal'], true), 404);
        $profile = $project->owningWorkspace->businessProfile;
        $path = $profile?->{$type.'_path'};
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $profile->{$type.'_original_name'}, [
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function canManage(Request $request): bool
    {
        return in_array($request->attributes->get('currentWorkspaceRole'), ['owner', 'admin'], true);
    }
}
