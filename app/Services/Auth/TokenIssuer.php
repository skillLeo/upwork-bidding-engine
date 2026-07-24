<?php

namespace App\Services\Auth;

use App\Enums\AuthEventType;
use App\Jobs\SendNewDeviceAlertJob;
use App\Models\AuthEvent;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The ONE place a session token is minted.
 *
 * Every sign-in path (password, OTP, dev quick-login, and later OAuth)
 * routes through here so device metadata, the audit row, and the new-device
 * alert cannot be forgotten on one path and present on another — which is
 * exactly how a security feature ends up with a hole in it.
 */
class TokenIssuer
{
    /**
     * A device is "new" if this ip+agent pair has not been seen for the user
     * within this window. 90 days rather than forever: a laptop that has not
     * signed in for three months is worth re-confirming.
     */
    private const NEW_DEVICE_WINDOW_DAYS = 90;

    public function __construct(
        private DeviceLabeller $labeller,
        private IpLocator $locator,
    ) {}

    /**
     * @return array{token: string, user: User}
     */
    public function issue(User $user, ?Request $request = null, string $name = 'dashboard'): array
    {
        $request ??= request();
        $ip = $request?->ip();
        $agent = $request?->userAgent();

        // Checked BEFORE the token row is written — otherwise the token we
        // are about to create is itself the "previous sighting" and no alert
        // would ever fire.
        $isNewDevice = $this->isNewDevice($user, $ip, $agent);

        $token = $user->createToken($name);

        // Sanctum's createToken() does not know about these columns, so they
        // are written straight after. forceFill avoids depending on the
        // model's fillable list, which belongs to the framework.
        $token->accessToken->forceFill([
            'ip_address' => $ip,
            'user_agent' => $agent ? mb_substr($agent, 0, 1000) : null,
            'device_label' => $this->labeller->label($agent),
            'approx_location' => $this->locator->locate($ip),
            'tenant_id' => Tenancy::id(),
        ])->save();

        AuthEvent::record(AuthEventType::LoginSuccess, user: $user, request: $request);

        if ($isNewDevice) {
            // Queued: a sign-in must not wait on SMTP, and a mail failure
            // must never turn a valid login into an error.
            SendNewDeviceAlertJob::dispatch(
                $user->id,
                $this->labeller->label($agent) ?? 'an unrecognised device',
                $this->locator->locate($ip),
                $ip,
            )->afterResponse();
        }

        return ['token' => $token->plainTextToken, 'user' => $user];
    }

    private function isNewDevice(User $user, ?string $ip, ?string $agent): bool
    {
        // With nothing to identify the device by, every request would look
        // new and the alert would fire on every login. Silence is better.
        if ($ip === null || $agent === null) {
            return false;
        }

        // The user's very first token is not a "new device" — it is signup.
        // Alerting there is noise that trains people to ignore the email.
        $hasHistory = PersonalAccessToken::where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->exists();

        if (! $hasHistory) {
            return false;
        }

        $seenRecently = PersonalAccessToken::where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->where('ip_address', $ip)
            ->where('user_agent', mb_substr($agent, 0, 1000))
            ->where('created_at', '>=', now()->subDays(self::NEW_DEVICE_WINDOW_DAYS))
            ->exists();

        return ! $seenRecently;
    }
}
