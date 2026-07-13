<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppWebhookController extends Controller
{
    public function __construct(protected SettingsService $settings) {}

    /**
     * Meta's one-time subscription handshake: GET with hub.mode/hub.verify_token/
     * hub.challenge (PHP mangles the dots to underscores in $_GET automatically).
     * The settings schema has no separate "verify token" field, so we reuse
     * whatsapp_token here — it's the one secret only we and Meta's dashboard know.
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = (string) $request->query('hub_challenge', '');

        $expected = $this->settings->whatsappToken();

        if ($mode !== 'subscribe' || ! $expected || $token !== $expected) {
            ActivityLog::record(ActivityType::WebhookRejected, meta: [
                'source' => 'whatsapp_verify',
                'ip' => $request->ip(),
            ]);

            return response('Forbidden', 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Inbound bidder replies + delivery/read status callbacks for messages we
     * sent. We record everything to the activity feed; turning a freeform
     * WhatsApp reply into a specific lead/client action is a deliberate,
     * explicit step the bidder takes in the dashboard (Client Memory's reply
     * box), not something guessed from webhook payloads.
     */
    public function receive(Request $request): JsonResponse
    {
        $payload = $request->all();

        foreach ((array) Arr::get($payload, 'entry', []) as $entry) {
            foreach ((array) Arr::get($entry, 'changes', []) as $change) {
                $value = (array) Arr::get($change, 'value', []);

                foreach ((array) Arr::get($value, 'messages', []) as $message) {
                    ActivityLog::record(ActivityType::ReplyReceived, meta: [
                        'channel' => 'whatsapp',
                        'from' => Arr::get($message, 'from'),
                        'type' => Arr::get($message, 'type'),
                        'text' => Arr::get($message, 'text.body'),
                        'wamid' => Arr::get($message, 'id'),
                    ]);
                }

                foreach ((array) Arr::get($value, 'statuses', []) as $status) {
                    ActivityLog::record('whatsapp_status_'.Arr::get($status, 'status', 'unknown'), meta: [
                        'wamid' => Arr::get($status, 'id'),
                        'recipient' => Arr::get($status, 'recipient_id'),
                    ]);
                }
            }
        }

        // Meta expects a fast 200 no matter what — it retries aggressively otherwise.
        return response()->json(['data' => ['status' => 'received']]);
    }
}
