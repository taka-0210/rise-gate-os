<?php

namespace App\Services;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AccountLoginLimiter
{
    public function ensureNotLimited(string $email, string $ip): void
    {
        $retryAfter = max(
            RateLimiter::availableIn($this->identityKey($email, $ip)),
            RateLimiter::availableIn($this->ipKey($ip)),
        );

        if (
            RateLimiter::tooManyAttempts($this->identityKey($email, $ip), config('account.login.max_attempts_per_minute'))
            || RateLimiter::tooManyAttempts($this->ipKey($ip), config('account.login.max_attempts_per_ip_per_minute'))
        ) {
            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => $retryAfter,
            ]);
        }
    }

    public function hit(string $email, string $ip): void
    {
        $decay = config('account.login.decay_seconds');
        RateLimiter::hit($this->identityKey($email, $ip), $decay);
        RateLimiter::hit($this->ipKey($ip), $decay);
    }

    public function clearIdentity(string $email, string $ip): void
    {
        RateLimiter::clear($this->identityKey($email, $ip));
    }

    private function identityKey(string $email, string $ip): string
    {
        return 'account-login:identity:'.hash('sha256', Str::lower(trim($email)).'|'.$ip);
    }

    private function ipKey(string $ip): string
    {
        return 'account-login:ip:'.hash('sha256', $ip);
    }
}
