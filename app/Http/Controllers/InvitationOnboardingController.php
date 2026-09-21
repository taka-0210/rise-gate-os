<?php

namespace App\Http\Controllers;

use App\Models\OrganizationInvitation;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\Organization\OrganizationInvitationAcceptance;
use App\Services\Organization\OrganizationInvitationClaim;
use App\Services\Organization\OrganizationSessionContext;
use App\Services\ProductOrganization\ProductOrganizationAdmission;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvitationOnboardingController extends Controller
{
    public function claim(
        Request $request,
        string $invitation,
        OrganizationInvitationClaim $claim,
    ): RedirectResponse {
        $record = OrganizationInvitation::query()->where('public_id', $invitation)->first();
        $token = (string) $request->query('token');
        if (! $record || ! $record->isPending() || $record->isExpired()
            || $token === '' || ! hash_equals($record->token_hash, hash('sha256', $token))) {
            throw ValidationException::withMessages(['invitation' => 'この招待は使用できません。最新の招待メールを確認してください。']);
        }
        $claim->remember($request, $record, $token);

        if ($request->user()
            && ! hash_equals($record->normalized_email, strtolower(trim($request->user()->email)))) {
            abort(403);
        }

        return redirect()->route('invitations.onboarding');
    }

    public function show(Request $request, OrganizationInvitationClaim $claim): View
    {
        $invitation = $claim->current($request, false, true)->load(['organization.standardWorkspace', 'groups']);
        $user = $request->user();
        $existingAccount = User::query()->whereRaw('LOWER(email) = ?', [$invitation->normalized_email])->exists();
        if ($user && ! hash_equals($invitation->normalized_email, strtolower(trim($user->email)))) {
            abort(403);
        }
        $membership = $user ? OrganizationUser::query()
            ->where('organization_id', $invitation->organization_id)
            ->where('user_id', $user->id)
            ->first() : null;

        return view('invitations.onboarding', compact('invitation', 'user', 'existingAccount', 'membership'));
    }

    public function register(
        Request $request,
        OrganizationInvitationClaim $claim,
        OrganizationInvitationAcceptance $acceptance,
        ProductOrganizationAdmission $productAdmission,
    ): RedirectResponse {
        abort_if(Auth::check(), 404);
        $invitation = $claim->current($request);
        if (User::query()->whereRaw('LOWER(email) = ?', [$invitation->normalized_email])->exists()) {
            return redirect()->route('login')->with('status', '既存AccountでLoginして招待を続けてください。');
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $user = DB::transaction(function () use ($request, $invitation, $validated, $acceptance, $productAdmission): User {
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $invitation->normalized_email,
                    'password' => $validated['password'],
                    'is_system_admin' => false,
                    'is_active' => true,
                ]);
                $productAdmission->registerUnstarted($user, 'provenance:staff_invitation');
                $acceptance->prepare($request, $user);

                return $user;
            });
        } catch (QueryException $exception) {
            if (User::query()->whereRaw('LOWER(email) = ?', [$invitation->normalized_email])->exists()) {
                return redirect()->route('login')->with('status', 'Accountが作成済みです。Loginして招待を続けてください。');
            }
            throw $exception;
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('access_mode', 'workspace');
        $request->session()->put('credential_generation', (int) $user->credential_generation);

        return redirect()->route('invitations.onboarding')->with('status', 'Accountを作成しました。Email確認後に所属を開始できます。');
    }

    public function prepare(
        Request $request,
        OrganizationInvitationAcceptance $acceptance,
    ): RedirectResponse {
        $acceptance->prepare($request, $request->user());

        return back()->with('status', '招待先の本人確認ができました。');
    }

    public function accept(
        Request $request,
        OrganizationInvitationAcceptance $acceptance,
        OrganizationSessionContext $sessionContext,
    ): RedirectResponse {
        $invitation = $acceptance->accept($request, $request->user())->load('organization.standardWorkspace');
        $membership = OrganizationUser::query()
            ->where('organization_id', $invitation->organization_id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
        $sessionContext->select($request, $membership);

        $workspaceIds = $request->user()->workspaces()
            ->where('workspaces.organization_id', $invitation->organization_id)
            ->where('workspaces.status', 'active')
            ->pluck('workspaces.id');
        if ($workspaceIds->count() === 1) {
            $request->session()->put('current_workspace_id', $workspaceIds->first());
        }

        return redirect()->route($workspaceIds->count() > 1 ? 'workspaces.index' : 'company.home')
            ->with('status', 'Organizationへの所属を開始しました。');
    }
}
