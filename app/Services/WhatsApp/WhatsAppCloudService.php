<?php

namespace App\Services\WhatsApp;

use App\Services\SettingsService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Meta's official WhatsApp Business Cloud API.
 *
 * This replaces the OpenClaw/Baileys path, which drives WhatsApp Web through
 * a QR-paired companion session on a Mac behind an ngrok tunnel. That design
 * has two fatal problems: it dies whenever the Mac or tunnel is off (which is
 * why production last delivered a WhatsApp message on 2026-07-22 and has
 * thrown ERR_NGROK_3200 ever since), and reverse-engineered companion
 * automation is what gets numbers banned with RESTRICT_ALL_COMPANIONS.
 *
 * The Cloud API is plain server-to-server HTTPS on Meta's own infrastructure:
 * no Mac, no tunnel, no persistent process — which is the only shape that
 * works on Hostinger shared hosting — and no ToS problem.
 *
 * MESSAGE TYPES AND WHY IT MATTERS FOR COST:
 *   - Inside the 24-hour customer service window (i.e. after the operator
 *     messages us), we may send free-form text.
 *   - Outside it, the first message must be a pre-approved TEMPLATE. Ours is
 *     registered as UTILITY (transactional), never MARKETING, which is
 *     roughly a fifth of the price per message.
 * sendAlert() picks the right one automatically from the last inbound time.
 */
class WhatsAppCloudService
{
    /** Meta's Graph API version this integration is written against. */
    public const GRAPH_VERSION = 'v21.0';

    /** Meta's service window: free-form replies are only allowed this long after an inbound message. */
    public const SERVICE_WINDOW_HOURS = 24;

    public function __construct(protected SettingsService $settings) {}

    /**
     * Every credential must be present before we attempt a call — a partial
     * config should fail closed and silently defer to the other channels,
     * not throw on a lead alert.
     */
    public function isConfigured(): bool
    {
        return (bool) $this->settings->get('whatsapp_cloud_enabled')
            && trim((string) $this->settings->get('whatsapp_phone_number_id')) !== ''
            && trim((string) $this->settings->get('whatsapp_access_token')) !== ''
            && trim((string) $this->recipient()) !== '';
    }

    public function recipient(): string
    {
        // E.164 without the leading '+' is what the Graph API expects.
        return ltrim(trim((string) $this->settings->get('bidder_whatsapp')), '+');
    }

    protected function client(): PendingRequest
    {
        return Http::withToken((string) $this->settings->get('whatsapp_access_token'))
            ->acceptJson()
            ->timeout(20)
            // Connection-level blips only; a 4xx from Meta is a real answer
            // and must not be retried into a rate limit.
            ->retry([1000, 3000], 0, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException);
    }

    protected function endpoint(): string
    {
        $phoneId = (string) $this->settings->get('whatsapp_phone_number_id');

        return 'https://graph.facebook.com/'.self::GRAPH_VERSION.'/'.$phoneId.'/messages';
    }

    /**
     * True while we're inside Meta's 24-hour window, during which free-form
     * (cheaper, richer) messages are allowed. Stamped by the inbound webhook.
     */
    public function withinServiceWindow(): bool
    {
        $last = $this->settings->get('whatsapp_last_inbound_at');

        if (! $last) {
            return false;
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $last)
                ->gt(now()->subHours(self::SERVICE_WINDOW_HOURS));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Send a lead alert, choosing the correct message type for where we are
     * in the service window. Returns the Graph message id on success.
     *
     * @param  array<int, string>  $templateParams  ordered {{1}}, {{2}}, ... values
     */
    public function sendAlert(string $text, array $templateParams = []): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        return $this->withinServiceWindow()
            ? $this->sendText($text)
            : $this->sendTemplate($templateParams ?: [$text]);
    }

    /** Free-form text. Only valid inside the 24-hour service window. */
    public function sendText(string $text): ?string
    {
        $response = $this->client()->post($this->endpoint(), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->recipient(),
            'type' => 'text',
            // Link previews add a render delay and can leak the dashboard URL
            // into Meta's crawler; the message carries the link as plain text.
            'text' => ['preview_url' => false, 'body' => $text],
        ]);

        $response->throw();

        return $response->json('messages.0.id');
    }

    /**
     * Pre-approved UTILITY template — the only thing allowed to open a
     * conversation outside the service window.
     *
     * @param  array<int, string>  $params  ordered body parameters
     */
    public function sendTemplate(array $params): ?string
    {
        $response = $this->client()->post($this->endpoint(), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->recipient(),
            'type' => 'template',
            'template' => [
                'name' => (string) $this->settings->get('whatsapp_template_name', 'fresh_lead'),
                'language' => ['code' => (string) $this->settings->get('whatsapp_template_language', 'en')],
                'components' => [[
                    'type' => 'body',
                    'parameters' => array_values(array_map(
                        fn ($p) => ['type' => 'text', 'text' => (string) $p],
                        $params,
                    )),
                ]],
            ],
        ]);

        $response->throw();

        return $response->json('messages.0.id');
    }

    /**
     * Records that the operator just messaged us, which opens/refreshes the
     * 24-hour free-form window. Called by the inbound webhook.
     */
    public function stampInboundNow(): void
    {
        $this->settings->set('whatsapp_last_inbound_at', now()->toIso8601String());
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        if (! (bool) $this->settings->get('whatsapp_cloud_enabled')) {
            return ['success' => false, 'message' => 'WhatsApp Cloud API is switched off in Settings.'];
        }

        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => 'Missing a phone number ID, access token, or your WhatsApp number.'];
        }

        try {
            $id = $this->sendAlert('SkillLeo test message — your WhatsApp Cloud API connection is working.');

            return ['success' => true, 'message' => 'Test message accepted by Meta'.($id ? " (id {$id})." : '.')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Meta rejected the message: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }
}
