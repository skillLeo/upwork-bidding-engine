<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\AuthEventType;
use App\Models\ActivityLog;
use App\Models\AuthEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FALaravel\Google2FA;

/**
 * Authenticator-app TOTP (P6). Email OTP is circular on this system — the
 * code is delivered through a Gmail account whose app password lives inside
 * the app the OTP protects (see ProfileSecuritySection). TOTP breaks that
 * circle: nothing about verifying a code depends on this app's own mail
 * config.
 *
 * Enrolment is two calls, never one: enroll() generates and PERSISTS a
 * secret but leaves two_factor_confirmed_at null, so
 * User::hasTotpEnabled() (secret AND confirmed_at) stays false — a
 * generated-but-never-confirmed secret activates nothing. confirm() is the
 * only place that timestamp is ever set, and only after a real code from
 * the app verifies against it. "Activate on display alone" is structurally
 * impossible here, not just avoided by convention.
 */
class TotpController extends Controller
{
    private const RECOVERY_CODE_COUNT = 8;

    private const WINDOW = 1; // ±30 seconds each direction, matching AuthController::TOTP_WINDOW

    public function enroll(Request $request, Google2FA $google2fa): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $secret = $google2fa->generateSecretKey();

        $user->forceFill([
            'google2fa_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return response()->json(['data' => [
            'secret' => $secret,
            'otpauth_url' => $google2fa->getQRCodeUrl(
                config('app.name', 'SkillLeo'),
                $user->email,
                $secret,
            ),
        ]]);
    }

    /**
     * The ONE valid code required before activation. Also issues the 8
     * recovery codes here (not on enroll) — codes issued before the
     * authenticator app is proven working would be recovery codes for a
     * second factor that may never actually get confirmed.
     */
    public function confirm(Request $request, Google2FA $google2fa): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(['code' => ['required', 'string']]);

        if ($user->google2fa_secret === null) {
            throw ValidationException::withMessages([
                'code' => ['Start enrolment first.'],
            ]);
        }

        if ($google2fa->verifyKey($user->google2fa_secret, trim($validated['code']), self::WINDOW) === false) {
            throw ValidationException::withMessages([
                'code' => ['That code is incorrect. Check the time on your phone and try again.'],
            ]);
        }

        [$plainCodes, $hashedCodes] = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $hashedCodes,
        ])->save();

        ActivityLog::record(ActivityType::SettingUpdated, subject: $user, userId: $user->id, meta: [
            'action' => 'totp_enabled',
        ]);
        AuthEvent::record(AuthEventType::TwoFactorEnabled, user: $user, request: $request);

        return response()->json(['data' => [
            'message' => 'Authenticator app enabled.',
            'recovery_codes' => $plainCodes,
        ]]);
    }

    /**
     * Regenerating is the only way to see a set of recovery codes again
     * (they're stored hashed) — invalidates every previous code, matching
     * "regenerating invalidates all previous codes."
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->hasTotpEnabled(), 422, 'Authenticator app is not enabled.');

        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages(['password' => ['Current password is incorrect.']]);
        }

        [$plainCodes, $hashedCodes] = $this->generateRecoveryCodes();

        $user->forceFill(['two_factor_recovery_codes' => $hashedCodes])->save();

        ActivityLog::record(ActivityType::SettingUpdated, subject: $user, userId: $user->id, meta: [
            'action' => 'totp_recovery_codes_regenerated',
        ]);

        return response()->json(['data' => ['recovery_codes' => $plainCodes]]);
    }

    /** Requires the current password — same lock as disabling email OTP would if that existed. */
    public function disable(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages(['password' => ['Current password is incorrect.']]);
        }

        $user->forceFill([
            'google2fa_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        ActivityLog::record(ActivityType::SettingUpdated, subject: $user, userId: $user->id, meta: [
            'action' => 'totp_disabled',
        ]);
        AuthEvent::record(AuthEventType::TwoFactorDisabled, user: $user, request: $request);

        return response()->json(['data' => ['message' => 'Authenticator app disabled.']]);
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>} [plaintext codes, hashed codes]
     */
    private function generateRecoveryCodes(): array
    {
        $plain = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            // Grouped for readability (xxxx-xxxx); Str::random avoids
            // ambiguous characters no more than the app's other random
            // tokens do — length (8) is what keeps guessing infeasible.
            $plain[] = mb_strtolower(Str::random(4)).'-'.mb_strtolower(Str::random(4));
        }

        return [$plain, array_map(fn (string $code) => Hash::make($code), $plain)];
    }
}
