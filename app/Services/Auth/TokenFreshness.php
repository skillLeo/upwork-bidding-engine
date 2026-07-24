<?php

namespace App\Services\Auth;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * IDLE expiry: a token unused for 14 days is treated as expired.
 *
 * Sanctum's own `expiration` config covers ABSOLUTE age (issued more than 30
 * days ago). It has no concept of "unused for a fortnight", which is the
 * more useful signal — a token last touched two weeks ago is either a device
 * someone stopped using or one someone else now has.
 *
 * WHY THIS IS NOT A MIDDLEWARE, despite the brief asking for one:
 * Sanctum's Guard stamps last_used_at = now() as part of AUTHENTICATING the
 * request (Guard.php:56/167), which happens before any route middleware
 * runs. A middleware therefore always observes a freshly-touched timestamp
 * and can never detect idleness — verified by test, which failed exactly
 * that way before this moved. Hooking Sanctum's own validation callback runs
 * inside isValidAccessToken(), i.e. BEFORE that stamp.
 *
 * The brief's actual requirement — "enforced at the moment of use, not by a
 * scheduled sweep" — is fully met, and rather more strongly: this is part of
 * token validation itself, so it applies to every authenticated route
 * automatically and cannot be forgotten on one.
 */
class TokenFreshness
{
    public const IDLE_DAYS = 14;

    /**
     * @param  mixed  $accessToken
     */
    public function isFresh($accessToken): bool
    {
        if (! $accessToken instanceof PersonalAccessToken) {
            return true;
        }

        // A token never used is judged on its issue date — otherwise a null
        // last_used_at would mean a token that can never expire by idleness.
        $lastActive = $accessToken->last_used_at ?? $accessToken->created_at;

        if ($lastActive === null) {
            return true;
        }

        return $lastActive->gt(now()->subDays(self::IDLE_DAYS));
    }

    /**
     * Enforced at the moment of use: a stale token is deleted outright, not
     * merely refused, so it cannot linger and cannot be revived by a clock
     * change. Returns whether the token may be used.
     *
     * @param  mixed  $accessToken
     */
    public function enforce($accessToken, bool $sanctumSaysValid): bool
    {
        if (! $sanctumSaysValid) {
            return false;
        }

        if ($this->isFresh($accessToken)) {
            return true;
        }

        $accessToken->delete();

        return false;
    }
}
