<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Login lockout with exponential backoff.
 *
 * Laravel's RateLimiter gives a flat window; this needs the window to GROW
 * with repeated failure, so a patient attacker gets slower rather than
 * settling into a steady 5-per-minute grind forever.
 *
 *   attempts 1-5   free
 *   6th            locked  1 minute
 *   then           2, 4, 8 minutes, capped at 15
 *
 * Keyed on email+IP together. Email alone would let one attacker lock a real
 * user out of their own account by failing on purpose (a denial-of-service
 * dressed as a security feature); IP alone would let one attacker spread
 * across many accounts from one machine untouched.
 */
class LoginThrottle
{
    public const MAX_ATTEMPTS = 5;

    private const MAX_LOCK_MINUTES = 15;

    /** Counters outlive any single lock so backoff keeps escalating. */
    private const COUNTER_TTL_MINUTES = 60;

    /**
     * Throws (422) when locked. Called before credentials are checked, so a
     * locked attacker cannot even test a password.
     */
    public function assertNotLocked(string $email, string $ip): void
    {
        $until = Cache::get($this->lockKey($email, $ip));

        if ($until === null) {
            return;
        }

        $seconds = max(1, now()->diffInSeconds($until, absolute: false));

        throw ValidationException::withMessages([
            // Deliberately generic and identical to a failed password: this
            // must not confirm whether the address exists.
            'email' => ['Too many sign-in attempts. Try again in '.ceil($seconds / 60).' minute(s).'],
        ])->status(429);
    }

    public function recordFailure(string $email, string $ip): void
    {
        $key = $this->counterKey($email, $ip);
        $failures = (int) Cache::get($key, 0) + 1;

        Cache::put($key, $failures, now()->addMinutes(self::COUNTER_TTL_MINUTES));

        if ($failures < self::MAX_ATTEMPTS) {
            return;
        }

        // 5th failure -> 1 min, 6th -> 2, 7th -> 4, 8th -> 8, then 15 forever.
        $minutes = min(self::MAX_LOCK_MINUTES, 2 ** ($failures - self::MAX_ATTEMPTS));

        Cache::put($this->lockKey($email, $ip), now()->addMinutes($minutes), now()->addMinutes($minutes));
    }

    /**
     * A correct password clears both the lock and the counter — the point is
     * to slow guessing, not to punish someone who mistyped and then got it
     * right.
     */
    public function clear(string $email, string $ip): void
    {
        Cache::forget($this->counterKey($email, $ip));
        Cache::forget($this->lockKey($email, $ip));
    }

    public function failureCount(string $email, string $ip): int
    {
        return (int) Cache::get($this->counterKey($email, $ip), 0);
    }

    /** Hashed: raw email addresses should not sit in cache keys or Redis logs. */
    private function fingerprint(string $email, string $ip): string
    {
        return sha1(Str::lower(trim($email)).'|'.$ip);
    }

    private function counterKey(string $email, string $ip): string
    {
        return 'login:fails:'.$this->fingerprint($email, $ip);
    }

    private function lockKey(string $email, string $ip): string
    {
        return 'login:lock:'.$this->fingerprint($email, $ip);
    }
}
