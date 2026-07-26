<?php

namespace App\Http\Controllers\Platform;

use App\Authorization\PlatformOwnership;
use App\Authorization\PlatformRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

/**
 * Transfer of the single platform ownership (P8).
 *
 * The most dangerous button in the product: it hands over every workspace,
 * every platform prompt, and the pooled AI credentials in one call, and the
 * account losing it cannot take it back. So the challenge is deliberately
 * heavier than any other action here — only the sitting owner may start it,
 * they re-enter their password, and if they hold TOTP they pass that too. A
 * stolen session alone is not enough.
 */
class OwnershipController extends Controller
{
    private const TOTP_WINDOW = 1;

    public function __construct(protected PlatformOwnership $ownership) {}

    public function show(Request $request): JsonResponse
    {
        $owner = $this->ownership->current();

        return response()->json(['data' => [
            'current_owner' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
            ] : null,
            'is_current_owner' => $owner !== null && (int) $owner->id === (int) $request->user()->id,
            'requires_totp' => (bool) $request->user()->hasTotpEnabled(),
        ]]);
    }

    public function transfer(Request $request, Google2FA $google2fa): JsonResponse
    {
        $actor = $request->user();

        // Platform staff generally reach this controller (platform.staff), so
        // narrow it here: support and billing may never move ownership.
        abort_unless(
            $actor->platformRole() === PlatformRole::Owner,
            403,
            'Only the platform owner can transfer platform ownership.',
        );

        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'password' => ['required', 'string'],
            'totp_code' => ['sometimes', 'nullable', 'string'],
        ]);

        if (! Hash::check($validated['password'], (string) $actor->password)) {
            throw ValidationException::withMessages([
                'password' => ['That password is incorrect.'],
            ]);
        }

        if ($actor->hasTotpEnabled()) {
            $code = trim((string) ($validated['totp_code'] ?? ''));

            if ($code === '' || $google2fa->verifyKey((string) $actor->google2fa_secret, $code, self::TOTP_WINDOW) === false) {
                throw ValidationException::withMessages([
                    'totp_code' => ['That authentication code is not valid.'],
                ]);
            }
        }

        $recipient = User::find($validated['user_id']);

        if ($recipient === null) {
            throw ValidationException::withMessages([
                'user_id' => ['That account does not exist.'],
            ]);
        }

        try {
            $this->ownership->transfer($recipient, expectedCurrent: $actor);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['user_id' => [$e->getMessage()]]);
        }

        return response()->json(['data' => [
            'message' => "Platform ownership transferred to {$recipient->email}. You are no longer platform staff.",
        ]]);
    }
}
