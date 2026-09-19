<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountAudit;
use App\Services\AccountCredentialService;
use App\Services\AccountMailDispatcher;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request, AccountMailDispatcher $mail, AccountAudit $audit): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $normalized = Str::lower(trim($validated['email']));

        (new Timebox)->call(function () use ($normalized, $mail, $audit): void {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$normalized])->first();
            if (! $user || ! $user->is_active) {
                $audit->record('account.password_reset.requested', 'accepted');

                return;
            }

            try {
                $mail->assertConfigured();
                $token = PasswordBroker::broker()->createToken($user);
                DB::table('password_reset_tokens')
                    ->where('email', $user->email)
                    ->update(['credential_generation' => $user->credential_generation]);
                $mail->sendPasswordReset($user, $token);
                $audit->record('account.password_reset.requested', 'accepted', $user, $user);
            } catch (\Throwable) {
                $audit->record('account.password_reset.requested', 'enqueue_failed', $user, $user);
            }
        }, 200000);

        return back()->with('status', '入力内容に対応するAccountが利用可能な場合、再設定Mailを送信しました。');
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function update(
        Request $request,
        AccountCredentialService $credentials,
        AccountAudit $audit,
    ): RedirectResponse {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $success = DB::transaction(function () use ($validated, $credentials): bool {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [Str::lower(trim($validated['email']))])
                ->lockForUpdate()
                ->first();
            if (! $user || ! $user->is_active) {
                return false;
            }

            $record = DB::table('password_reset_tokens')
                ->where('email', $user->email)
                ->lockForUpdate()
                ->first();
            $expires = (int) config('auth.passwords.users.expire', 60);
            $valid = $record
                && (int) $record->credential_generation === (int) $user->credential_generation
                && Carbon::parse($record->created_at)->addMinutes($expires)->isFuture()
                && Hash::check($validated['token'], $record->token);
            if (! $valid) {
                return false;
            }

            $user->password = $validated['password'];
            $credentials->rotate($user, 'account.password_reset', $user);
            event(new PasswordReset($user));

            return true;
        });

        if (! $success) {
            $audit->record('account.password_reset', 'rejected');

            return back()->withErrors(['email' => 'この再設定URLは使用できません。もう一度Mailを送信してください。'])->withInput($request->only('email'));
        }

        return redirect()->route('login')->with('status', 'Passwordを再設定しました。新しいPasswordでログインしてください。');
    }
}
