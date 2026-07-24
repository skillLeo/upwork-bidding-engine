<?php

namespace App\Http\Controllers;

use App\Authorization\TenantRole;
use App\Enums\AuthEventType;
use App\Http\Resources\UserResource;
use App\Models\AuthEvent;
use App\Models\Invitation;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\TokenIssuer;
use App\Services\Members\InvitationService;
use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use PragmaRX\Google2FALaravel\Google2FA;

/**
 * Google OAuth (P6). Sign in / sign up with Google, on the SAME rules a
 * password login already follows: NEVER silently link a Google identity to
 * an existing email/password account (a known account-takeover path — an
 * attacker who controls attacker@victim-email's Google account could
 * otherwise sign in as them with zero knowledge of the real password),
 * always pass a required second factor, always respect signup_mode.
 *
 * Two things this controller deliberately does NOT do, both load-bearing:
 *
 *   1. Trust Socialite's stateless() mode for CSRF safety. stateless()
 *      skips the state parameter entirely (it lives in the session, which
 *      this API has none of) — without it, an attacker's OWN valid Google
 *      authorization code, sent to a victim as a link, would silently log
 *      the victim into an account the ATTACKER controls (login CSRF). state()
 *      below implements the same protection without a session: a random
 *      nonce, cached server-side before the redirect, single-use, verified
 *      before the callback does anything else.
 *
 *   2. Put a bearer token, a 2FA challenge, or an account's email in the
 *      REDIRECT URL. A URL is logged (browser history, server access logs,
 *      Referer headers) in places a POST body isn't. Every outcome —
 *      success, a 2FA challenge, or a link-confirmation prompt — is instead
 *      stashed server-side behind one opaque, single-use handoff code; the
 *      SPA exchanges that code via exchange() (a POST) for the real payload.
 */
class SocialAuthController extends Controller
{
    private const LINK_TOKEN_TTL_MINUTES = 10;

    private const STATE_TTL_MINUTES = 10;

    private const HANDOFF_TTL_MINUTES = 2;

    private const TOTP_WINDOW = 1; // matches AuthController::TOTP_WINDOW

    public function redirect(): RedirectResponse
    {
        $state = Str::random(40);
        Cache::put("google_oauth_state:{$state}", true, now()->addMinutes(self::STATE_TTL_MINUTES));

        return Socialite::driver('google')->stateless()->with(['state' => $state])->redirect();
    }

    public function callback(Request $request, InvitationService $invitations): RedirectResponse
    {
        $frontend = rtrim((string) config('skillleo.frontend_url'), '/');

        // CSRF: see the class docblock. Checked, and consumed, before
        // anything else — a missing/reused/unknown state never reaches
        // Socialite at all.
        $state = (string) $request->query('state');

        if ($state === '' || ! Cache::pull("google_oauth_state:{$state}")) {
            return redirect($frontend.'/auth/google/finish?error='.urlencode('This sign-in link is invalid or has expired. Try again.'));
        }

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            report($e);

            return redirect($frontend.'/auth/google/finish?error='.urlencode('Could not verify that Google account. Try again.'));
        }

        $email = Str::lower((string) $googleUser->getEmail());
        $googleId = (string) $googleUser->getId();

        if ($email === '' || $googleId === '') {
            return redirect($frontend.'/auth/google/finish?error='.urlencode('Google did not return an email address.'));
        }

        // 1. Already linked — straight sign-in, through the same TOTP/OTP
        // branch a password login uses (OAuth is not a 2FA bypass).
        $linkedAccount = SocialAccount::where('provider', 'google')->where('provider_id', $googleId)->first();

        if ($linkedAccount !== null) {
            return $this->finish($linkedAccount->user, $request, $frontend);
        }

        // 2. An account with this EMAIL already exists via another method —
        // never auto-link. Stash a short-lived, server-side confirmation and
        // send the browser to a password prompt instead.
        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            $linkToken = Str::random(40);
            Cache::put(
                "google_link:{$linkToken}",
                ['user_id' => $existing->id, 'google_id' => $googleId],
                now()->addMinutes(self::LINK_TOKEN_TTL_MINUTES),
            );

            // Neither the link token nor the email is a secret on its own —
            // the point is keeping the account's address out of the URL/logs.
            $handoff = $this->stashHandoff(['link_required' => true, 'link_token' => $linkToken, 'email' => $email]);

            return redirect($frontend.'/auth/google/finish?handoff='.$handoff);
        }

        // 3. No account at all. A pending invite for this email always gets
        // in regardless of signup_mode (an invite IS the authorization);
        // otherwise signup_mode decides — refused for closed/invite_code.
        // TENANCY: an unauthenticated Google sign-up has no tenant bound, so
        // matching a pending invite by email is deliberately cross-tenant —
        // the same shape as InvitationService::findPending() by token.
        $invitation = Tenancy::asPlatform(fn () => Invitation::query()
            ->where('email', $email)->whereNull('accepted_at')->where('expires_at', '>', now())->first());

        if ($invitation !== null) {
            $existedBefore = User::where('email', $email)->exists(); // always false here, kept explicit for clarity
            $user = $invitations->accept($invitation, ['name' => (string) ($googleUser->getName() ?: Str::before($email, '@'))]);

            if (! $existedBefore) {
                // A brand-new account created entirely via Google has no
                // password at all — see User::hasPassword().
                $user->forceFill(['password' => null])->save();
            }

            $this->attachSocialAccount($user, $googleId);

            // TENANCY: resolving the invite's own tenant by id — the tenants
            // table is never tenant-scoped, and no tenant is bound yet here.
            $tenant = Tenancy::asPlatform(fn () => Tenant::find($invitation->tenant_id));

            return $this->finish($user, $request, $frontend, $tenant);
        }

        $signupMode = (string) app(SettingsService::class)->get('signup_mode', 'invite_code');

        if ($signupMode !== 'open') {
            return redirect($frontend.'/auth/google/finish?error='
                .urlencode('This workspace is invite-only. Ask an admin for an invite.'));
        }

        $tenant = Tenancy::current();

        if ($tenant === null) {
            return redirect($frontend.'/auth/google/finish?error='.urlencode('No workspace is configured for open sign-up.'));
        }

        $user = User::create([
            'name' => (string) ($googleUser->getName() ?: Str::before($email, '@')),
            'email' => $email,
            'password' => null,
            'role' => 'bidder',
        ]);
        $tenant->users()->syncWithoutDetaching([$user->id => ['joined_at' => now()]]);
        Tenancy::runAs($tenant, fn () => $user->syncRoles([TenantRole::Bidder->value]));
        $this->attachSocialAccount($user, $googleId);

        return $this->finish($user, $request, $frontend, $tenant);
    }

    /**
     * Confirms case 2 above: proves the account's PASSWORD (not merely
     * possession of a Google session) before the link is created. This
     * password check is the one thing standing between "the emails match"
     * and an attacker who controls the Google side signing in as someone
     * else — see the class docblock.
     */
    public function link(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'link_token' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Cache::get(), not pull() — a mistyped password must not burn the
        // link token, or a legitimate retry after a typo would be refused
        // with "expired" instead of "wrong password". Only a SUCCESSFUL
        // link consumes it (forget() below), same retry allowance a normal
        // password login already gets.
        $payload = Cache::get("google_link:{$validated['link_token']}");

        if (! is_array($payload)) {
            throw ValidationException::withMessages(['link_token' => ['This link request has expired. Try signing in with Google again.']]);
        }

        $user = User::find($payload['user_id']);

        if ($user === null || ! $user->hasPassword() || ! Hash::check($validated['password'], (string) $user->password)) {
            throw ValidationException::withMessages(['password' => ['That password is incorrect.']]);
        }

        Cache::forget("google_link:{$validated['link_token']}");

        $this->attachSocialAccount($user, $payload['google_id']);

        AuthEvent::record(AuthEventType::SocialAccountLinked, user: $user, request: $request);

        return response()->json(['data' => $this->resolvePayload($this->challengeOrToken($user, $request))]);
    }

    /**
     * The other half of the handoff pattern (see class docblock) — the SPA
     * posts the opaque code from the callback redirect's ?handoff= and gets
     * back whichever real payload was stashed for it: a token, a 2FA
     * challenge, or a link-confirmation prompt. Single-use and short-lived.
     */
    public function exchange(Request $request): JsonResponse
    {
        $validated = $request->validate(['handoff' => ['required', 'string']]);

        $payload = Cache::pull("google_handoff:{$validated['handoff']}");

        if (! is_array($payload)) {
            throw ValidationException::withMessages(['handoff' => ['This sign-in link has expired. Try again.']]);
        }

        return response()->json(['data' => $payload]);
    }

    private function attachSocialAccount(User $user, string $googleId): void
    {
        SocialAccount::updateOrCreate(
            ['user_id' => $user->id, 'provider' => 'google'],
            ['provider_id' => $googleId, 'linked_at' => now()],
        );
    }

    /**
     * Bind the tenant (when known) and hand off to the same TOTP/OTP/token
     * branch a password login uses, then redirect the browser back to the
     * SPA carrying an opaque handoff code for whichever of those three
     * states resulted — never the token/challenge itself (see class
     * docblock).
     */
    private function finish(User $user, Request $request, string $frontend, ?Tenant $tenant = null): RedirectResponse
    {
        $tenant ??= Tenancy::current();

        $result = $tenant !== null
            ? Tenancy::runAs($tenant, fn () => $this->challengeOrToken($user, $request))
            : $this->challengeOrToken($user, $request);

        return redirect($frontend.'/auth/google/finish?handoff='.$this->stashHandoff($this->resolvePayload($result)));
    }

    private function stashHandoff(array $payload): string
    {
        $handoff = Str::random(40);
        Cache::put("google_handoff:{$handoff}", $payload, now()->addMinutes(self::HANDOFF_TTL_MINUTES));

        return $handoff;
    }

    /**
     * challengeOrToken()'s 'user' entry is a UserResource — fine to return
     * straight from a JSON controller action, but not safe to store in
     * Cache as-is (serialization of a resource wrapping a live model is
     * fragile across cache drivers). Resolved to a plain array once, here.
     */
    private function resolvePayload(array $result): array
    {
        if (isset($result['user']) && $result['user'] instanceof UserResource) {
            $result['user'] = $result['user']->resolve();
        }

        return $result;
    }

    /**
     * The exact same three-way branch AuthController::login() uses after a
     * password check succeeds — TOTP first (recommended, no email
     * round-trip), then email OTP, then a straight token. OAuth reaching
     * this method at all is already proof of identity; from here it is
     * indistinguishable from a password login.
     */
    private function challengeOrToken(User $user, Request $request): array
    {
        if ($user->hasTotpEnabled()) {
            $challenge = Str::random(40);
            $user->forceFill([
                'two_factor_challenge' => $challenge,
                'two_factor_expires_at' => now()->addMinutes(10),
                'two_factor_attempts' => 0,
            ])->save();

            return ['requires_totp' => true, 'challenge' => $challenge];
        }

        if ($user->two_factor_enabled) {
            $code = (string) random_int(100000, 999999);
            $challenge = Str::random(40);

            $user->forceFill([
                'two_factor_code' => Hash::make($code),
                'two_factor_challenge' => $challenge,
                'two_factor_expires_at' => now()->addMinutes(10),
                'two_factor_attempts' => 0,
            ])->save();

            \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\OtpCodeMail($code));

            return ['requires_otp' => true, 'challenge' => $challenge];
        }

        $issued = app(TokenIssuer::class)->issue($user, $request);

        return ['token' => $issued['token'], 'user' => new UserResource($user)];
    }
}
