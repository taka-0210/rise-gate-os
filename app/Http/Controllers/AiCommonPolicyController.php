<?php

namespace App\Http\Controllers;

use App\Models\AiResourcePolicy;
use App\Models\OrganizationAiPolicy;
use App\Services\AiCommon\AiCommonAccess;
use App\Services\AiCommon\AiCommonManagementContext;
use App\Services\AiCommon\AiCommonPolicyWriter;
use App\Services\AiCommon\AiCommonResourcePolicyWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiCommonPolicyController extends Controller
{
    public function edit(Request $request, AiCommonAccess $access): View
    {
        $organization = $request->attributes->get('currentCompany');
        $access->authorizePolicyManager($request->user(), $organization);

        return view('ai-common.policy', [
            'policy' => OrganizationAiPolicy::query()->where('organization_id', $organization->id)->first(),
            'resourcePolicies' => AiResourcePolicy::query()->where('organization_id', $organization->id)->latest('id')->get(),
            'managementDocuments' => app(AiCommonManagementContext::class)->catalogue($request->user(), $organization),
        ]);
    }

    public function update(Request $request, AiCommonPolicyWriter $writer): RedirectResponse
    {
        $organization = $request->attributes->get('currentCompany');
        $validated = $request->validate([
            'is_enabled' => ['nullable', 'boolean'],
            'allows_transcription' => ['nullable', 'boolean'],
            'allowed_categories' => ['nullable', 'array'],
            'allowed_categories.*' => ['string'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
        ]);
        $writer->update($request->user(), $organization, $validated, $validated['expected_version'] ?? null);

        return back()->with('status', 'Organization AI Policyを更新しました。');
    }

    public function resource(Request $request, AiCommonResourcePolicyWriter $writer): RedirectResponse
    {
        $validated = $request->validate([
            'resource_type' => ['required', 'string'],
            'resource_public_id' => ['required', 'string', 'max:64'],
            'allows_ai_reference' => ['nullable', 'boolean'],
        ]);
        $writer->update(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $validated['resource_type'],
            $validated['resource_public_id'],
            filter_var($validated['allows_ai_reference'] ?? false, FILTER_VALIDATE_BOOL),
        );

        return back()->with('status', 'Resource AI Reference Policyを更新しました。');
    }
}
