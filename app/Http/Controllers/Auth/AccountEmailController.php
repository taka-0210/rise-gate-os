<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AccountEmailRequest;
use App\Services\AccountEmailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AccountEmailController extends Controller
{
    public function verifyCurrent(Request $request, AccountEmailService $service): RedirectResponse
    {
        if ($request->user()->email_verified_at) {
            return back()->with('status', '現在のEmailは確認済みです。');
        }

        try {
            $service->requestCurrentVerification($request->user());
        } catch (\RuntimeException) {
            throw ValidationException::withMessages(['email' => '確認Mailを準備できませんでした。時間をおいて再送してください。']);
        }

        return back()->with('status', '現在のEmailへ確認Mailを送信しました。');
    }

    public function requestChange(Request $request, AccountEmailService $service): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'email' => ['required', 'email', 'max:255'],
        ]);
        try {
            $service->requestEmailChange($request->user(), $validated['email']);
        } catch (\RuntimeException) {
            throw ValidationException::withMessages(['email' => '確認Mailを準備できませんでした。現在のEmailは変更されていません。']);
        }

        return back()->with('status', '候補Emailへ確認Mailを送信しました。確認までは現在のEmailを使用します。');
    }

    public function resendChange(Request $request, AccountEmailService $service): RedirectResponse
    {
        try {
            $service->resendEmailChange($request->user());
        } catch (\RuntimeException) {
            throw ValidationException::withMessages(['email' => '確認Mailを再送できませんでした。時間をおいてお試しください。']);
        }

        return back()->with('status', '候補Emailへ確認Mailを再送しました。以前のURLは使用できません。');
    }

    public function cancelChange(Request $request, AccountEmailService $service): RedirectResponse
    {
        $service->cancelEmailChange($request->user());

        return back()->with('status', 'Email変更を取り消しました。');
    }

    public function confirm(
        Request $request,
        string $requestId,
        AccountEmailService $service,
    ): RedirectResponse {
        if (! Auth::check()) {
            return redirect()->route('login')->with('status', '本人のAccountでログイン後、MailのURLをもう一度開いてください。');
        }
        abort_unless($request->user()->is_active, 403);

        $record = AccountEmailRequest::query()->where('public_id', $requestId)->first();
        if (! $record || $record->user_id !== $request->user()->id) {
            abort(403);
        }

        $result = $service->confirm($request->user(), $requestId, (string) $request->query('token'));
        if ($result === 'invalid') {
            throw ValidationException::withMessages(['email' => 'この確認URLは使用できません。Profileから再送してください。']);
        }
        if ($result === 'changed') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Emailを変更しました。新しいEmailでログインしてください。');
        }

        return redirect()->route('account.profile')->with('status', '現在のEmailを確認しました。');
    }
}
