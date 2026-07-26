<?php

namespace App\Http\Controllers;

use App\Authorization\PlatformRole;
use App\Authorization\RoleProvisioner;
use App\Authorization\TenantRole;
use App\Enums\ActivityType;
use App\Enums\AuthEventType;
use App\Enums\UserRole;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Mail\OtpCodeMail;
use App\Models\ActivityLog;
use App\Models\AuthEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\LoginThrottle;
use App\Services\Auth\TokenIssuer;
use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use App\Tenancy\TenantTeamResolver;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FALaravel\Google2FA;
use Spatie\Permission\PermissionRegistrar;

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

        // 'platform_owner' is not a UserRole — it lives on its own column, in
        // a separate namespace from the tenant roles (see PlatformRole). It is
        // accepted here as a target because "sign in as the super admin" is
        // the single most useful shortcut while the product is being built,
        // and picking the oldest 'admin' only happens to hit that account
        // today by coincidence of ids.
        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in([...UserRole::cases(), 'platform_owner'])],
        ]);

        if ($validated['role'] === 'platform_owner') {
            $user = User::where('platform_role', PlatformRole::Owner->value)->oldest('id')->first();
            $label = 'platform owner';
        } else {
            $role = UserRole::from($validated['role']);
            $user = User::where('role', $role)->oldest('id')->first();
            $label = $role->value;
        }

        if (! $user) {
            throw ValidationException::withMessages([
                'role' => ["No {$label} user exists to sign in as."],
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

        if ($token instanceof PersonalAccessToken) {
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

        if ($token instanceof PersonalAccessToken && $token->impersonator_id !== null) {
            $data['impersonating'] = [
                'reason' => $token->impersonation_reason,
                'expires_at' => optional($token->impersonation_expires_at)
                    ? Carbon::parse($token->impersonation_expires_at)->toIso8601String()
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
     * Public self-serve sign-up. Reachable ONLY when signup_mode is "open"
     * (the default is invite_code, so this is off until an admin turns it on);
     * the mode is re-checked here server-side, never trusted from the client.
     *
     * A successful registration provisions a brand-new workspace with the
     * registrant as its owner — the workspace name on the form is real. A
     * fresh tenant needs no seeding: SettingsService resolves every key to its
     * schema default until the owner configures Vollna/AI, so the workspace is
     * functional (just idle) from the first request. Everything is wrapped in
     * one transaction so a half-provisioned tenant can never be left behind.
     */
    public function register(Request $request): JsonResponse
    {
        $mode = (string) app(SettingsService::class)->get('signup_mode', 'invite_code');

        if ($mode !== 'open') {
            // A truthful, non-enumerating message: it is about the workspace
            // policy, not about whether the email exists.
            throw ValidationException::withMessages([
                'email' => ['Open sign-up is closed. You need an invitation to join.'],
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'workspace_name' => ['required', 'string', 'max:255'],
        ]);

        // The workspace, the user, and the membership are created atomically.
        [$tenant, $user] = DB::transaction(function () use ($validated) {
            $tenant = Tenant::create([
                'name' => $validated['workspace_name'],
                'slug' => $this->uniqueSlug($validated['workspace_name']),
                'plan' => 'free',
                'status' => Tenant::STATUS_ACTIVE,
            ]);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => UserRole::Admin->value,
            ]);

            $tenant->users()->syncWithoutDetaching([$user->id => ['joined_at' => now()]]);
            $tenant->update(['owner_user_id' => $user->id]);

            return [$tenant->fresh(), $user];
        });

        // Role provisioning runs AFTER the commit so Spatie's team-scoped
        // lookups query committed rows. It is the one step that would leave a
        // workspace ownerless if it failed mid-way — acceptable because it is
        // idempotent (findOrCreate) and the owner grant can always be re-run,
        // whereas a half-rolled-back tenant could not.
        $this->provisionNewWorkspace($tenant, $user);

        // Bind the new workspace before issuing the token so the token, its
        // device metadata and the audit row all record the right tenant.
        $tenant = $user->tenants()->first();
        $issued = Tenancy::runAs($tenant, fn () => $this->issueToken($user, $request));

        ActivityLog::record(ActivityType::UserLoggedIn, subject: $user, userId: $user->id);

        return response()->json(['data' => $issued], 201);
    }

    /**
     * Provision the three roles for a brand-new workspace and make the
     * registrant its owner.
     *
     * RoleProvisioner::provision() pins the Spatie team id and filters the
     * role lookup by it (see its docblock), so it is safe to call while
     * ANOTHER tenant's roles are already loaded in this process — which is
     * exactly the situation self-serve sign-up creates. The owner grant still
     * happens here, under the same explicit pin, because provision() creates
     * roles without assigning anyone to them.
     */
    protected function provisionNewWorkspace(Tenant $tenant, User $user): void
    {
        app(RoleProvisioner::class)->provision($tenant);

        TenantTeamResolver::pinnedTo($tenant->id, fn () => $user->syncRoles([TenantRole::Owner->value]));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * A slug that is unique across the tenants table. The tenants table is
     * never tenant-scoped, so this reads it as platform.
     */
    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';

        // TENANCY: slug uniqueness is a global property of the tenants table,
        // which is never tenant-scoped; there is also no tenant bound yet on
        // this public, pre-auth request. Read as platform, deliberately.
        return Tenancy::asPlatform(function () use ($base) {
            $slug = $base;
            $n = 1;

            while (Tenant::where('slug', $slug)->exists()) {
                $slug = $base.'-'.(++$n);
            }

            return $slug;
        });
    }

    /**
     * @return array{token: string, user: UserResource}
     */
    protected function issueToken(User $user, ?Request $request = null): array
    {
        // One funnel for every sign-in path, so device metadata, the audit
        // row and the new-device alert cannot be present on one route and
        // forgotten on another — AND so the token is always stamped with a
        // workspace this person actually belongs to.
        $workspace = $this->workspaceFor($user);

        // The UserResource is built INSIDE the binding, not after it.
        //
        // It reports tenant_role and the flat permission list, and both are
        // resolved through Spatie's team resolver at the moment they are
        // read. Building it outside meant they were computed against
        // whatever tenant the request had fallen back to — so the sign-in
        // response handed the browser an identity with ZERO permissions, and
        // the app, which hides any control the user cannot use, rendered no
        // navigation at all. It only appeared after a manual reload, when
        // /me happened to be asked again under the right workspace.
        return Tenancy::runAs($workspace, function () use ($user, $request, $workspace) {
            $issued = $this->tokens->issue($user, $request);

            $resource = TenantTeamResolver::pinnedTo(
                $workspace->id,
                fn () => (new UserResource($user->fresh()))->toArray($request ?? request()),
            );

            return ['token' => $issued['token'], 'user' => $resource];
        });
    }

    /**
     * The workspace this sign-in belongs in.
     *
     * WHY THIS IS NOT SIMPLY "the bound tenant". Sign-in happens on the
     * public host, which names no workspace, so the request falls back to
     * the configured default — workspace 1. TokenIssuer then stamped THAT on
     * the token, and every later request resolved from it. The result: a
     * second workspace's owner was permanently bound to the founder's
     * workspace, where they hold no role, so their navigation was empty and
     * every settings page refused them. The token carried the wrong
     * workspace from the very first request.
     *
     * Preference order: the bound tenant IF they are a member of it (so a
     * real tenant subdomain still wins), then a workspace they own, then any
     * workspace they belong to. Someone who belongs to none keeps the
     * fallback — they have nothing else, and the role checks refuse them
     * everywhere regardless.
     */
    protected function workspaceFor(User $user): Tenant
    {
        $current = Tenancy::current();

        // TENANCY: the tenants table is never tenant-scoped, and this reads
        // only the workspaces THIS user is already a member of.
        $theirs = Tenancy::asPlatform(fn () => $user->tenants()->orderBy('id')->get());

        if ($current !== null && $theirs->contains('id', $current->id)) {
            return $current;
        }

        return $theirs->firstWhere('owner_user_id', $user->id)
            ?? $theirs->first()
            ?? $current
            ?? abort(403, 'This account does not belong to any workspace.');
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
