<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WhatsAppService
{
    protected const API_VERSION = 'v21.0';

    public function __construct(protected SettingsService $settings) {}

    protected function client(): PendingRequest
    {
        return Http::baseUrl('https://graph.facebook.com/'.self::API_VERSION)
            ->withToken((string) $this->settings->whatsappToken())
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 500, function (\Exception $exception, PendingRequest $request) {
                return $exception instanceof ConnectionException;
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function sendText(string $to, string $body): array
    {
        $phoneId = $this->settings->whatsappPhoneId();

        $response = $this->client()->post("/{$phoneId}/messages", [
            'messaging_product' => 'whatsapp',
            'to' => $this->normalizeNumber($to),
            'type' => 'text',
            'text' => ['body' => Str::limit($body, 4096, '')],
        ]);

        $response->throw();

        return (array) $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function sendLeadCard(Lead $lead, string $dashboardUrl): array
    {
        $score = $lead->score !== null ? "{$lead->score}/10" : 'n/a';

        $lines = [
            "🟢 *New ready lead — score {$score}*",
            $lead->title,
            '',
            "Budget: {$lead->budget}",
            "Proposals so far: {$lead->proposal_count}",
            "Why: {$lead->score_reason}",
            '',
            'Proposal to paste on Upwork:',
            '—',
            (string) $lead->proposal_text,
            '—',
            '',
            "Open in dashboard: {$dashboardUrl}",
        ];

        return $this->sendText((string) $this->settings->bidderWhatsapp(), implode("\n", $lines));
    }

    /**
     * @return array<string, mixed>
     */
    public function sendFollowUp(Lead $lead, string $dashboardUrl): array
    {
        $text = "⏰ Reminder: \"{$lead->title}\" was sent with no reply yet. Consider a polite follow-up.\n{$dashboardUrl}";

        return $this->sendText((string) $this->settings->bidderWhatsapp(), $text);
    }

    protected function normalizeNumber(string $number): string
    {
        return (string) preg_replace('/[^0-9]/', '', $number);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        $phoneId = $this->settings->whatsappPhoneId();

        if (! $phoneId || ! $this->settings->whatsappToken()) {
            return ['success' => false, 'message' => 'WhatsApp token or phone number ID is not configured.'];
        }

        try {
            $response = $this->client()->get("/{$phoneId}", ['fields' => 'verified_name,display_phone_number']);

            if ($response->successful()) {
                $name = $response->json('verified_name') ?? $response->json('display_phone_number') ?? 'unknown number';

                return ['success' => true, 'message' => "Connected to WhatsApp sender: {$name}."];
            }

            return ['success' => false, 'message' => "WhatsApp API responded with HTTP {$response->status()}."];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Could not reach the WhatsApp API: '.$e->getMessage()];
        }
    }
}
