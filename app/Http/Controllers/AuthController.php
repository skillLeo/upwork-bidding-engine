<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\AuthEventType;
use App\Enums\UserRole;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Mail\OtpCodeMail;
use App\Models\ActivityLog;
use App\Models\AuthEvent;
use App\Models\User;
use App\Services\Auth\LoginThrottle;
use App\Services\Auth\TokenIssuer;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FALaravel\Google2FA;

class AuthController extends Controller
{
    /** Wrong codes allowed against one challenge before it is destroyed. Shared by email-OTP and TOTP/recovery. */
    private const MAX_OTP_ATTEMPTS = 5;

    /** ±30 seconds each direction (google2fa's window is measured in 30s periods) — no wider. */
    private const TOTP_WINDOW = 1;

    public function __construct(
        protected TokenIssuer $tokens,
        protected LoginThrottle $throttle,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $email = (string) $credentials['email'];
        $ip = (string) $request->ip();

        // Checked BEFORE the password is tested, so a locked-out attacker
        // cannot use this endpoint as a password oracle at all.
        $this->throttle->assertNotLocked($email, $ip);

        // Explicit 'web' guard, not the ambient default — Sanctum's own
        // Authenticate middleware calls Auth::shouldUse('sanctum') on every
        // authenticated request, which would otherwise silently redirect
        // this Auth::once() call to the token guard (no once() method)
        // the next time this process handles an authenticated request
        // first (observed in tests, which reuse one process across many
        // requests; a traditional PHP-FPM request never hits this, but
        // relying on ambient guard state here was fragile regardless).
        if (! Auth::guard('web')->once($credentials)) {
            $this->throttle->recordFailure($email, $ip);

            AuthEvent::record(AuthEventType::LoginFailed, emailAttempted: $email, request: $request);

            // Identical message whether the address exists or not. Anything
            // more specific is an account-enumeration oracle.
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        // A correct password clears the lock: the goal is slowing guesses,
        // not punishing someone who mistyped and then got it right.
        $this->throttle->clear($email, $ip);

        // TOTP first — it is the recommended factor precisely because it
        // does not round-trip through the email account this app itself
        // sends mail from (see TotpController's docblock). A user with both
        // enrolled is only ever asked for one.
        if ($user->hasTotpEnabled()) {
            return response()->json(['data' => $this->issueTotpChallenge($user)]);
        }

        if ($user->two_factor_enabled) {
            return response()->json(['data' => $this->issueOtpChallenge($user)]);
        }

        ActivityLog::record(ActivityType::UserLoggedIn, subject: $user, userId: $user->id);

        return response()->json(['data' => $this->issueToken($user, $request)]);
    }

    /**
     * Second step of a TOTP login — the authenticator-app code, OR one of
     * the 8 single-use recovery codes. Mirrors verifyOtp()'s challenge/
     * lockout shape exactly, so the two second-factor UIs behave identically
     * from the client's point of view.
     */
    public function verifyTotp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $user = User::where('two_factor_challenge', $validated['challenge'])->first();

        $expired = $user === null || ! $user->two_factor_expires_at || $user->two_factor_expires_at->isPast();

        if (! $expired) {
            $code = trim($validated['code']);
            $google2fa = app(Google2FA::class);

            $viaTotp = $user->google2fa_secret !== null
                && $google2fa->verifyKey($user->google2fa_secret, $code, self::TOTP_WINDOW) !== false;

            $viaRecovery = ! $viaTotp && $this->consumeRecoveryCodeIfValid($user, $code);

            if ($viaTotp || $viaRecovery) {
                $this->clearOtpChallenge($user);

                ActivityLog::record(ActivityType::UserLoggedIn, subject: $user, userId: $user->id, meta: [
                    'via' => $viaRecovery ? 'totp_recovery_code' : 'totp',
                ]);

                return response()->json(['data' => $this->issueToken($user, $request)]);
            }
        }

        if ($user !== null) {
            $attempts = (int) $user->two_factor_attempts + 1;

            if ($attempts >= self::MAX_OTP_ATTEMPTS) {
                $this->clearOtpChallenge($user);

                AuthEvent::record(AuthEventType::LoginFailed, user: $user, request: $request);

                throw ValidationException::withMessages([
                    'code' => ['Too many incorrect codes. Start the sign-in again.'],
                ]);
            }

            $user->forceFill(['two_factor_attempts' => $attempts])->save();
        }

        AuthEvent::record(
            AuthEventType::LoginFailed,
            user: $user,
            emailAttempted: $user?->email,
            request: $request,
        );

        throw ValidationException::withMessages([
            'code' => ['That code is incorrect or has expired.'],
        ]);
    }

    /**
     * Single-use: the matched code is removed from the stored (hashed)
     * array immediately, so a captured/reused code never works twice — the
     * literal thing recovery codes exist to survive.
     */
    protected function consumeRecoveryCodeIfValid(User $user, string $code): bool
    {
        $hashed = (array) ($user->two_factor_recovery_codes ?? []);

        foreach ($hashed as $i => $candidate) {
            if (Hash::check($code, (string) $candidate)) {
                unset($hashed[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($hashed)])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * Second step of an email-OTP login — takes the opaque challenge from
     * login() (never the user id/email directly, so a client can't just
     * guess its way past this with a different account) plus the 6-digit
     * code just emailed, and completes the sign-in.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $user = User::where('two_factor_challenge', $validated['challenge'])->first();

        $invalid = ! $user
            || ! $user->two_factor_expires_at
            || $user->two_factor_expires_at->isPast()
            || ! Hash::check($validated['code'], (string) $user->two_factor_code);

        if ($invalid) {
            // A 6-digit code is only ~1M possibilities, and an IP rate limit
            // alone does not bound guesses against ONE challenge. Five wrong
            // answers destroy the challenge outright — the user restarts the
            // login rather than the attacker continuing to grind.
            if ($user !== null) {
                $attempts = (int) $user->two_factor_attempts + 1;

                if ($attempts >= self::MAX_OTP_ATTEMPTS) {
                    $this->clearOtpChallenge($user);

                    AuthEvent::record(AuthEventType::LoginFailed, user: $user, request: $request);

                    throw ValidationException::withMessages([
                        'code' => ['Too many incorrect codes. Start the sign-in again.'],
                    ]);
                }

                $user->forceFill(['two_factor_attempts' => $attempts])->save();
            }

            AuthEvent::record(
                AuthEventType::LoginFailed,
                user: $user,
                emailAttempted: $user?->email,
                request: $request,
            );

            throw ValidationException::withMessages([
                'code' => ['That code is incorrect or has expired.'],
            ]);
        }

        $this->clearOtpChallenge($user);

        ActivityLog::record(ActivityType::UserLoggedIn, subject: $user, userId: $user->id, meta: [
            'via' => 'otp',
        ]);

        return response()->json(['data' => $this->issueToken($user, $request)]);
    }

    /**
     * Passwordless sign-in as the first user of a role, for development.
     *
     * Gated on `skillleo.dev_quick_login`; 404s when off so the endpoint isn't
     * discoverable in a normal deployment. Resolving the user server-side is
     * what keeps real passwords out of the JS bundle.
     */
    public function devLogin(Request $request): JsonResponse
    {
        abort_unless(config('skillleo.dev_quick_login'), 404);

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::enum(UserRole::class)],
        ]);

        $role = UserRole::from($validated['role']);

        $user = User::where('role', $role)->oldest('id')->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'role' => ["No {$role->value} user exists to sign in as."],
            ]);
        }

        ActivityLog::record(ActivityType::UserLoggedIn, subject: $user, userId: $user->id, meta: [
            'via' => 'dev_quick_login',
        ]);

        return response()->json(['data' => $this->issueToken($user, $request)]);
    }

    public function logout(Request $request): JsonResponse
    {
        AuthEvent::record(AuthEventType::Logout, user: $request->user(), request: $request);

        // Only a real PersonalAccessToken can be deleted — a session-guard
        // request carries a TransientToken, which has no row to remove.
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['data' => ['message' => 'Logged out.']]);
    }

    public function me(Request $request): JsonResponse
    {
        $data = (new UserResource($request->user()))->resolve();

        // Surfaces the persistent impersonation banner even after a page
        // refresh, since impersonation state lives on the TOKEN, not the
        // user — see ImpersonationController::start().
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken && $token->impersonator_id !== null) {
            $data['impersonating'] = [
                'reason' => $token->impersonation_reason,
                'expires_at' => optional($token->impersonation_expires_at)
                    ? \Illuminate\Support\Carbon::parse($token->impersonation_expires_at)->toIso8601String()
                    : null,
            ];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Always responds success regardless of whether the email exists —
     * confirming/denying account existence to an unauthenticated caller is
     * its own information leak.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        return response()->json(['data' => [
            'message' => 'If that email is registered, a reset link is on its way.',
        ]]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset($validated, function (User $user) use ($validated) {
            $user->forceFill(['password' => $validated['password']])->save();
            $user->tokens()->delete();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['data' => ['message' => 'Password reset — sign in with your new password.']]);
    }

    /**
     * @return array{token: string, user: UserResource}
     */
    protected function issueToken(User $user, ?Request $request = null): array
    {
        // One funnel for every sign-in path, so device metadata, the audit
        // row and the new-device alert cannot be present on one route and
        // forgotten on another.
        $issued = $this->tokens->issue($user, $request);

        return [
            'token' => $issued['token'],
            'user' => new UserResource($user),
        ];
    }

    protected function clearOtpChallenge(User $user): void
    {
        $user->forceFill([
            'two_factor_code' => null,
            'two_factor_challenge' => null,
            'two_factor_expires_at' => null,
            'two_factor_attempts' => 0,
        ])->save();
    }

    /**
     * @return array{requires_otp: true, challenge: string}
     */
    protected function issueOtpChallenge(User $user): array
    {
        $code = (string) random_int(100000, 999999);
        $challenge = Str::random(40);

        $user->forceFill([
            'two_factor_code' => Hash::make($code),
            'two_factor_challenge' => $challenge,
            'two_factor_expires_at' => now()->addMinutes(10),
            'two_factor_attempts' => 0,
        ])->save();

        Mail::to($user->email)->send(new OtpCodeMail($code));

        return ['requires_otp' => true, 'challenge' => $challenge];
    }

    /**
     * No email round-trip — the app already has the code the moment the
     * user's authenticator does. two_factor_code stays null; verifyTotp()
     * checks the TOTP secret / recovery codes instead of a hash of this.
     *
     * @return array{requires_totp: true, challenge: string}
     */
    protected function issueTotpChallenge(User $user): array
    {
        $challenge = Str::random(40);

        $user->forceFill([
            'two_factor_challenge' => $challenge,
            'two_factor_expires_at' => now()->addMinutes(10),
            'two_factor_attempts' => 0,
        ])->save();

        return ['requires_totp' => true, 'challenge' => $challenge];
    }
}
