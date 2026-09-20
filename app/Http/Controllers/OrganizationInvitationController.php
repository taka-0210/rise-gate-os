<?php

namespace App\Http\Controllers;

use App\Models\OrganizationInvitation;
use App\Models\OrganizationUser;
use App\Services\Organization\OrganizationInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationInvitationController extends Controller
{
    public function store(Request $request, OrganizationInvitationService $service): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'organization_role' => ['sometimes', 'nullable', Rule::in(array_keys(OrganizationUser::organizationRoles()))],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer'],
            'request_id' => ['required', 'uuid', 'max:64'],
        ]);
        $service->issue(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $validated['email'],
            array_key_exists('organization_role', $validated) ? $validated['organization_role'] : null,
            $validated['group_ids'] ?? [],
            $validated['request_id'],
        );

        return back()->with('success', '招待を登録しました。配送状況は一覧で確認できます。');
    }

    public function resend(
        Request $request,
        OrganizationInvitation $organizationInvitation,
        OrganizationInvitationService $service,
    ): RedirectResponse {
        $validated = $request->validate(['request_id' => ['required', 'uuid', 'max:64']]);
        $service->resend(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $organizationInvitation,
            $validated['request_id'],
        );

        return back()->with('success', '最新世代の招待を再送しました。以前のリンクは使用できません。');
    }

    public function revoke(
        Request $request,
        OrganizationInvitation $organizationInvitation,
        OrganizationInvitationService $service,
    ): RedirectResponse {
        $validated = $request->validate(['request_id' => ['required', 'uuid', 'max:64']]);
        $service->revoke(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $organizationInvitation,
            $validated['request_id'],
        );

        return back()->with('success', '招待を取り消しました。');
    }
}
