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

    /** Store (or refresh) a device's push subscription. Deduped by endpoint. */
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'content_encoding' => ['nullable', 'string', 'max:16'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $validated['endpoint'])],
            [
                'endpoint' => $validated['endpoint'],
                'p256dh' => $validated['keys']['p256dh'],
                'auth_key' => $validated['keys']['auth'],
                'content_encoding' => $validated['content_encoding'] ?? 'aes128gcm',
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
}
