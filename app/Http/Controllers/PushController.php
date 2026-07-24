<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushController extends Controller
{
    public function __construct(protected WebPushService $push) {}

    /** The browser needs the VAPID public key to create a subscription. */
    public function vapidKey(): JsonResponse
    {
        return response()->json(['data' => [
            'public_key' => $this->push->publicKey(),
            'configured' => $this->push->configured(),
        ]]);
    }

    /**
     * Hosts a browser push endpoint is ever legitimately on. The server POSTs
     * to whatever we store here, so this allowlist is what stops an
     * authenticated user from registering an internal URL and turning us into
     * an SSRF proxy (loopback / 169.254.169.254 / RFC1918 can never match).
     */
    protected const ALLOWED_PUSH_HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', 'web.push.apple.com'];

    protected const ALLOWED_PUSH_SUFFIXES = ['.push.services.mozilla.com', '.notify.windows.com', '.push.apple.com'];

    /** Store (or refresh) a device's push subscription. Deduped by endpoint. */
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url:https'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'max:16'],
        ]);

        if (! $this->isKnownPushEndpoint($validated['endpoint'])) {
            return response()->json(['message' => 'Unrecognized push endpoint host.'], 422);
        }

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $validated['endpoint'])],
            [
                'endpoint' => $validated['endpoint'],
                'p256dh' => $validated['keys']['p256dh'],
                'auth_key' => $validated['keys']['auth'],
                'content_encoding' => $validated['content_encoding'] ?? 'aes128gcm',
                // Attributes the device to a person so Notification
                // preferences (P5) can filter it; a subscription from before
                // this shipped keeps user_id null and keeps receiving
                // everything unconditionally (see WebPushService::send()).
                'user_id' => $request->user()?->id,
            ],
        );

        return response()->json(['data' => ['subscribed' => true]]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate(['endpoint' => ['required', 'string']]);

        PushSubscription::query()
            ->where('endpoint_hash', hash('sha256', $validated['endpoint']))
            ->delete();

        return response()->json(['data' => ['unsubscribed' => true]]);
    }

    /**
     * True only for an https URL whose host is a real browser push service.
     * Anything else (internal host, IP literal, private range) is rejected.
     */
    protected function isKnownPushEndpoint(string $endpoint): bool
    {
        $host = parse_url($endpoint, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);

        if (in_array($host, self::ALLOWED_PUSH_HOSTS, true)) {
            return true;
        }

        foreach (self::ALLOWED_PUSH_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
