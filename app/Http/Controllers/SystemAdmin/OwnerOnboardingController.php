<?php

namespace App\Http\Controllers\SystemAdmin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OwnerOnboarding;
use App\Services\Organization\OwnerOnboardingAdministration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OwnerOnboardingController extends Controller
{
    public function index(): View
    {
        return view('system-admin.owner-onboardings.index', [
            'onboardings' => OwnerOnboarding::query()->with(['issuer', 'claimedUser', 'completedOrganization'])
                ->latest()->limit(100)->get(),
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'public_id', 'name']),
        ]);
    }

    public function store(Request $request, OwnerOnboardingAdministration $service): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'organization_name' => ['required', 'string', 'max:255'],
            'duplicate_decision' => ['required', Rule::in(['no_match', 'distinct_company'])],
            'distinct_company_reason' => ['nullable', 'string', 'max:500'],
            'request_id' => ['required', 'uuid', 'max:64'],
        ]);
        $onboarding = $service->issue(
            $request->user(), $validated['email'], $validated['organization_name'],
            $validated['duplicate_decision'], $validated['distinct_company_reason'] ?? null,
            $validated['request_id'],
        );

        $message = $onboarding->delivery_status === OwnerOnboarding::DELIVERY_FAILED
            ? '開始案件は保存しましたがMail投入に失敗しました。案件一覧から再送してください。開始管理番号: '.$onboarding->public_id
            : 'Owner開始案内を発行しました。開始管理番号: '.$onboarding->public_id;

        return back()->with('status', $message);
    }

    public function resend(Request $request, OwnerOnboarding $ownerOnboarding, OwnerOnboardingAdministration $service): RedirectResponse
    {
        $validated = $request->validate(['request_id' => ['required', 'uuid', 'max:64']]);
        $onboarding = $service->resend($request->user(), $ownerOnboarding, $validated['request_id']);

        if ($onboarding->delivery_status === OwnerOnboarding::DELIVERY_FAILED) {
            return back()->with('status', '開始案内のMail投入に失敗しました。設定を確認して再送してください。');
        }

        return back()->with('status', '同じ開始管理番号で案内を再送しました。旧URLは使用できません。');
    }

    public function revoke(Request $request, OwnerOnboarding $ownerOnboarding, OwnerOnboardingAdministration $service): RedirectResponse
    {
        $validated = $request->validate(['request_id' => ['required', 'uuid', 'max:64']]);
        $service->revoke($request->user(), $ownerOnboarding, $validated['request_id']);

        return back()->with('status', 'Owner開始案内を取り消しました。');
    }
}
