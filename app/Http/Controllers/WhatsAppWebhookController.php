<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Services\SettingsService;
use App\Services\WhatsApp\WhatsAppCloudService;
use App\Services\WhatsApp\WhatsAppCommandRouter;
use App\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Inbound side of the WhatsApp Cloud API — the thing the OpenClaw path could
 * never do, and therefore the reason BID/SKIP/PAUSE replies were impossible
 * until now.
 *
 * Two endpoints, both necessarily public (Meta holds no credential of ours):
 *   GET  — the one-time subscription handshake. Echo hub.challenge back in
 *          plain text, but only when hub.verify_token matches ours.
 *   POST — delivery of inbound messages, authenticated by the
 *          X-Hub-Signature-256 HMAC over the RAW body using the app secret.
 *          Unsigned or mis-signed requests are refused before anything is
 *          parsed, because acting on an unverified payload would let anyone
 *          who learns this URL mark leads bid or mute the operator's alerts.
 *
 * TENANCY: an inbound webhook carries no session, so the tenant is resolved
 * from the phone number ID Meta reports — never from anything the caller
 * chooses.
 */
class WhatsAppWebhookController extends Controller
{
    /** Meta's subscription handshake. */
    public function verify(Request $request): Response
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        $tenant = $this->resolveTenantByVerifyToken($token);

        if ($mode !== 'subscribe' || $tenant === null) {
            return response('Forbidden', 403);
        }

        // Meta requires the raw challenge echoed as text/plain.
        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request): Response
    {
        $payload = $request->json()->all();
        $phoneNumberId = data_get($payload, 'entry.0.changes.0.value.metadata.phone_number_id');

        $tenant = $this->resolveTenantByPhoneNumberId((string) $phoneNumberId);

        if ($tenant === null) {
            // Unknown number: acknowledge so Meta stops retrying, but do
            // nothing. Never 4xx a delivery we simply don't own.
            return response('', 200);
        }

        return Tenancy::runAs($tenant, function () use ($request, $payload) {
            $settings = app(SettingsService::class);
            $secret = (string) $settings->get('whatsapp_app_secret');

            if (! $this->signatureIsValid($request, $secret)) {
                ActivityLog::record(ActivityType::WebhookRejected, meta: [
                    'source' => 'whatsapp_cloud',
                    'reason' => 'invalid or missing X-Hub-Signature-256',
                ]);

                return response('Invalid signature', 403);
            }

            $messages = data_get($payload, 'entry.0.changes.0.value.messages', []);

            foreach ($messages as $message) {
                // Only plain text carries commands; ignore delivery receipts,
                // reactions, media and status callbacks.
                if (($message['type'] ?? null) !== 'text') {
                    continue;
                }

                $from = (string) ($message['from'] ?? '');
                $body = (string) data_get($message, 'text.body', '');

                // Only the workspace's own configured number may drive it.
                // Without this, anyone who messaged the business number could
                // mark leads bid or mute alerts.
                if ($from === '' || $from !== app(WhatsAppCloudService::class)->recipient()) {
                    continue;
                }

                $cloud = app(WhatsAppCloudService::class);
                $cloud->stampInboundNow();

                $reply = app(WhatsAppCommandRouter::class)->handle($body);

                if ($reply !== '') {
                    try {
                        // Inside the service window this inbound just opened,
                        // so a free-form reply is allowed and is free.
                        $cloud->sendText($reply);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }

            return response('', 200);
        });
    }

    /**
     * Constant-time HMAC check over the RAW request body — re-encoding the
     * parsed JSON would change the bytes and never match.
     */
    protected function signatureIsValid(Request $request, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }

    /**
     * TENANCY: the tenants table is never tenant-scoped, and an inbound
     * webhook has no tenant bound yet. Each workspace's own settings are read
     * inside its own context to find the one that owns this number.
     */
    protected function resolveTenantByPhoneNumberId(string $phoneNumberId): ?Tenant
    {
        if ($phoneNumberId === '') {
            return null;
        }

        return $this->firstTenantWhere(
            fn (SettingsService $s) => (string) $s->get('whatsapp_phone_number_id') === $phoneNumberId
        );
    }

    protected function resolveTenantByVerifyToken(string $token): ?Tenant
    {
        if ($token === '') {
            return null;
        }

        return $this->firstTenantWhere(function (SettingsService $s) use ($token) {
            $expected = (string) $s->get('whatsapp_verify_token');

            return $expected !== '' && hash_equals($expected, $token);
        });
    }

    /** @param  callable(SettingsService): bool  $matches */
    protected function firstTenantWhere(callable $matches): ?Tenant
    {
        // TENANCY: scanning every workspace to find the owner of an inbound
        // webhook. The tenants table is never tenant-scoped, and each
        // workspace's settings are read strictly inside its own runAs().
        $tenants = Tenancy::asPlatform(fn () => Tenant::query()->get());

        foreach ($tenants as $tenant) {
            $hit = Tenancy::runAs($tenant, fn () => $matches(app(SettingsService::class)));

            if ($hit) {
                return $tenant;
            }
        }

        return null;
    }
}
