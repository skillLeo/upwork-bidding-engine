<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Lead;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the external OpenClaw service, which is the thing that actually
 * calls Claude to score jobs, draft proposals/replies, and send WhatsApp
 * messages. OpenClaw is authenticated to Claude on its own (Claude Code CLI
 * subscription auth) — this app never holds or sends an Anthropic API key.
 */
class OpenClawService
{
    public function __construct(protected SettingsService $settings) {}

    protected function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->settings->openClawUrl(), '/'))
            ->withToken((string) $this->settings->openClawToken())
            ->acceptJson()
            // OpenClaw's scoring/drafting calls run through the Claude CLI, not
            // a direct API call, so they can genuinely take 30-150+ seconds —
            // a short timeout here just means a working-but-slow call gets
            // killed and retried needlessly.
            ->timeout(180)
            // Array form: each element is the delay (ms) before that attempt,
            // so this is 3 retries at 1s / 3s / 6s — real backoff, not fixed.
            // This only covers connection-level failures (network blips); the
            // job-level retry (tries/backoff on ScoreLeadJob etc.) is what
            // handles a request that reached OpenClaw but failed there.
            ->retry([1000, 3000, 6000], 0, function (\Exception $exception, PendingRequest $request) {
                return $exception instanceof ConnectionException;
            });
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{score: int, reason: string, proposal: string}
     */
    public function scoreAndWrite(Lead $lead, array $rules): array
    {
        $response = $this->client()->post('/task', [
            'skill' => 'score_and_write',
            'job' => [
                'title' => $lead->title,
                'brief' => $lead->full_brief,
                'budget' => $lead->budget,
                'proposals' => $lead->proposal_count,
                'client_spend' => $lead->client_spend,
                'client_hire_rate' => $lead->client_hire_rate,
                'payment_verified' => $lead->payment_verified,
            ],
            'rules' => [
                'stack' => $rules['stack_keywords'] ?? [],
                'min_budget' => $rules['min_budget'] ?? 0,
            ],
        ]);

        $response->throw();

        $data = (array) $response->json();

        return [
            'score' => (int) ($data['score'] ?? 0),
            'reason' => (string) ($data['reason'] ?? ''),
            'proposal' => (string) ($data['proposal'] ?? ''),
        ];
    }

    /**
     * @param  array<int, array{direction: string, text: string}>  $history
     * @return array{reply: string, needs_hassam: bool}
     */
    public function draftReply(Client $client, string $incomingMessage, array $history = []): array
    {
        $response = $this->client()->post('/task', [
            'skill' => 'draft_reply',
            'client' => [
                'name' => $client->name,
                'stage' => $client->stage->value,
                'budget_discussed' => $client->budget_discussed,
                'agreed_scope' => $client->agreed_scope,
                'notes' => $client->notes,
            ],
            'message' => $incomingMessage,
            'history' => $history,
        ]);

        $response->throw();

        $data = (array) $response->json();

        return [
            'reply' => (string) ($data['reply'] ?? ''),
            'needs_hassam' => (bool) ($data['needs_hassam'] ?? false),
        ];
    }

    /**
     * Sends a WhatsApp text via OpenClaw (already QR-linked to WhatsApp) —
     * no Meta Cloud API token/phone ID needed, just a destination number.
     *
     * @return array<string, mixed>
     */
    public function sendWhatsAppMessage(string $to, string $message): array
    {
        $response = $this->client()->post('/task', [
            'skill' => 'send_whatsapp_message',
            'to' => $to,
            'message' => $message,
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

        return $this->sendWhatsAppMessage((string) $this->settings->bidderWhatsapp(), implode("\n", $lines));
    }

    /**
     * @return array<string, mixed>
     */
    public function sendFollowUp(Lead $lead, string $dashboardUrl): array
    {
        $text = "⏰ Reminder: \"{$lead->title}\" was sent with no reply yet. Consider a polite follow-up.\n{$dashboardUrl}";

        return $this->sendWhatsAppMessage((string) $this->settings->bidderWhatsapp(), $text);
    }

    /**
     * Unlike testConnection() (a bare /health ping), this actually sends a
     * real WhatsApp message — the only way to prove the OpenClaw <-> WhatsApp
     * link genuinely delivers, not just that OpenClaw is reachable.
     *
     * @return array{success: bool, message: string}
     */
    public function testWhatsAppConnection(): array
    {
        $to = $this->settings->bidderWhatsapp();

        if (! $to) {
            return ['success' => false, 'message' => "No bidder WhatsApp number configured."];
        }

        if (! $this->settings->openClawUrl()) {
            return ['success' => false, 'message' => 'No OpenClaw URL configured.'];
        }

        try {
            $this->sendWhatsAppMessage($to, 'SkillLeo test message — your OpenClaw WhatsApp connection is working.');

            return ['success' => true, 'message' => "Test message sent to {$to}."];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Could not send WhatsApp test message: '.$e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        if (! $this->settings->openClawUrl()) {
            return ['success' => false, 'message' => 'No OpenClaw URL configured.'];
        }

        try {
            $response = $this->client()->get('/health');

            if ($response->successful()) {
                return ['success' => true, 'message' => 'OpenClaw responded OK.'];
            }

            return ['success' => false, 'message' => "OpenClaw responded with HTTP {$response->status()}."];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Could not reach OpenClaw: '.$e->getMessage()];
        }
    }
}
