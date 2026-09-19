<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCredentialSessionIsCurrent
{
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $key = 'credential_generation';
        if (! $request->session()->has($key)) {
            if ((int) $user->credential_generation > 1) {
                Auth::guard('web')->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with('status', 'Account情報が更新されました。もう一度ログインしてください。');
            }
            $request->session()->put($key, (int) $user->credential_generation);

            return $next($request);
        }

        if ((int) $request->session()->get($key) !== (int) $user->credential_generation) {
            Auth::guard('web')->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Account情報が更新されました。もう一度ログインしてください。');
        }

        return $next($request);
    }
}
