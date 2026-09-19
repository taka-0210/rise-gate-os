<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AccountEmailRequest;
use App\Models\OrganizationUser;
use App\Services\AccountAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('auth.profile', [
            'user' => $request->user(),
            'pendingEmailRequest' => AccountEmailRequest::query()
                ->where('user_id', $request->user()->id)
                ->where('purpose', AccountEmailRequest::PURPOSE_CHANGE_EMAIL)
                ->where('status', AccountEmailRequest::STATUS_PENDING)
                ->where('expires_at', '>', now())
                ->latest('id')
                ->first(),
            'organizationMemberships' => OrganizationUser::query()
                ->where('user_id', $request->user()->id)
                ->with(['organization', 'groups' => fn ($query) => $query->whereNull('archived_at')->orderBy('name')])
                ->orderBy('organization_id')
                ->get(),
        ]);
    }

    public function update(Request $request, AccountAudit $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $user->name = $validated['name'];
        $user->save();
        $audit->record('account.profile.updated', 'success', $user, $user);

        return back()->with('status', 'Profileを更新しました。');
    }
}
