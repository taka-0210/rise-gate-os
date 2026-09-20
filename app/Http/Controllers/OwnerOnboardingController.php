<?php

namespace App\Http\Controllers;

use App\Models\OrganizationUser;
use App\Models\OwnerOnboarding;
use App\Models\User;
use App\Services\Organization\OrganizationSessionContext;
use App\Services\Organization\OwnerOnboardingClaim;
use App\Services\Organization\OwnerOnboardingJourney;
use App\Services\Organization\OwnerOnboardingLegal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OwnerOnboardingController extends Controller
{
    public function claim(Request $request, string $onboarding, OwnerOnboardingClaim $claim, OwnerOnboardingLegal $legal): RedirectResponse
    {
        $this->legalReady($legal);
        $record = OwnerOnboarding::query()->where('public_id', $onboarding)->first();
        $token = (string) $request->query('token');
        if (! $record || ! $record->isIssued() || $record->isExpired()
            || $token === '' || ! hash_equals($record->token_hash, hash('sha256', $token))) {
            throw ValidationException::withMessages(['onboarding' => 'この開始案内は使用できません。最新の案内Mailを確認してください。']);
        }
        $claim->remember($request, $record, $token);
        if ($request->user() && ! hash_equals($record->normalized_email, strtolower(trim($request->user()->email)))) {
            abort(403);
        }

        return redirect()->route('owner-onboarding.show');
    }

    public function show(Request $request, OwnerOnboardingClaim $claim, OwnerOnboardingLegal $legal): View
    {
        $this->legalReady($legal);
        $onboarding = $claim->current($request, false, true)->load('completedOrganization.standardWorkspace');
        $user = $request->user();
        if ($user && ! hash_equals($onboarding->normalized_email, strtolower(trim($user->email)))) {
            abort(403);
        }

        return view('owner-onboarding.show', [
            'onboarding' => $onboarding,
            'user' => $user,
            'existingAccount' => User::query()->whereRaw('LOWER(email) = ?', [$onboarding->normalized_email])->exists(),
            'hasCurrentConsent' => $user ? $legal->hasCurrentConsent($user, $onboarding) : false,
            'legal' => $legal->current(),
        ]);
    }

    public function register(Request $request, OwnerOnboardingJourney $journey): RedirectResponse
    {
        abort_if(Auth::check(), 404);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'accept_terms' => ['accepted'],
            'accept_privacy' => ['accepted'],
        ]);
        try {
            $user = $journey->register($request, $validated['name'], $validated['password']);
        } catch (ValidationException $exception) {
            if ($exception->errors()['email'] ?? false) {
                return redirect()->route('login')->with('status', $exception->getMessage());
            }
            throw $exception;
        }
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('access_mode', 'workspace');
        $request->session()->put('credential_generation', (int) $user->credential_generation);

        return redirect()->route('owner-onboarding.show')->with('status', 'Accountを作成しました。現在のEmail確認後に会社を開始できます。');
    }

    public function prepare(Request $request, OwnerOnboardingJourney $journey): RedirectResponse
    {
        $request->validate(['accept_terms' => ['accepted'], 'accept_privacy' => ['accepted']]);
        $journey->prepare($request, $request->user());

        return back()->with('status', '本人Accountと現行文書への同意を確認しました。');
    }

    public function complete(
        Request $request,
        OwnerOnboardingJourney $journey,
        OrganizationSessionContext $sessionContext,
    ): RedirectResponse {
        $request->validate(['confirm_owner_responsibility' => ['accepted']]);
        $onboarding = $journey->complete($request, $request->user())->load('completedOrganization.standardWorkspace');
        $membership = OrganizationUser::query()
            ->where('organization_id', $onboarding->completed_organization_id)
            ->where('user_id', $request->user()->id)
            ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->firstOrFail();
        $sessionContext->select($request, $membership);
        $request->session()->put(OrganizationSessionContext::WORKSPACE_ID, $onboarding->completedOrganization->standard_workspace_id);
        $request->session()->put('access_mode', 'workspace');

        return redirect()->route('company.home')
            ->with('status', '会社の開始が完了しました。')
            ->with('owner_onboarding_completed', true);
    }

    private function legalReady(OwnerOnboardingLegal $legal): void
    {
        try {
            $legal->assertReady();
        } catch (\RuntimeException) {
            abort(503, 'Owner onboarding is not available until the official legal documents are published.');
        }
    }
}
