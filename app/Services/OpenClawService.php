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
     * A short-timeout ping used to decide, in under a few seconds, whether
     * it's worth attempting the real (up to 180s) scoring call inline on a
     * webhook request. OpenClaw runs on a machine that isn't always on
     * (currently a Mac, tunneled via ngrok) — this is what lets the webhook
     * fail fast and fall back to a queued retry instead of hanging the
     * Vollna delivery for minutes, which is what got a previous webhook
     * auto-disabled by Vollna after repeated timeouts.
     */
    public function isReachable(): bool
    {
        if (! $this->settings->openClawUrl()) {
            return false;
        }

        try {
            return Http::baseUrl(rtrim((string) $this->settings->openClawUrl(), '/'))
                ->withToken((string) $this->settings->openClawToken())
                ->timeout(5)
                ->get('/health')
                ->successful();
        } catch (\Throwable) {
            return false;
        }
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
     * Last-resort fallback for the leads search box - only reached when
     * NlSearchParser's free pattern matching recognized nothing at all in
     * the query (a rare, oddly-phrased search). Sends the query and the
     * criteria field list only, never the leads themselves, so this stays
     * a couple hundred tokens even when it does fire.
     *
     * A direct timeout (not client()'s 180s/retry setup, which is tuned
     * for the slow scoring/drafting calls). 30s, not shorter: the bridge
     * runs this through an OpenClaw CLI agent turn that measures ~10-15s
     * end-to-end when healthy (verified live), so anything tighter kills
     * every working call. Beyond 30s the search degrades to plain keyword
     * matching rather than hanging further; results are cached in
     * LeadController so a repeated query never pays this twice.
     *
     * The bridge's parse_search_query handler returns `{"criteria": {...}}`
     * using the field names below, normalized from the workspace
     * parse-search-query SKILL.md (its `status` key arrives here as
     * `status_in`, `understood: false` arrives as empty criteria).
     *
     * @return array<string, mixed>
     */
    public function parseSearchQuery(string $query): array
    {
        $response = Http::baseUrl(rtrim((string) $this->settings->openClawUrl(), '/'))
            ->withToken((string) $this->settings->openClawToken())
            ->acceptJson()
            ->timeout(30)
            ->post('/task', [
                'skill' => 'parse_search_query',
                'query' => $query,
                'fields' => [
                    'include_keywords', 'exclude_keywords', 'budget_min', 'budget_max', 'budget_type',
                    'score_min', 'proposal_max', 'connects_max', 'hire_rate_min', 'payment_verified_only',
                    'min_client_spend', 'client_countries_include', 'posted_within_minutes', 'status_in', 'is_favorite',
                ],
            ]);

        $response->throw();

        return (array) $response->json('criteria', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendLeadCard(Lead $lead, string $dashboardUrl): array
    {
        $score = $lead->score ?? 0;

        // "Ready" already means score >= score_cutoff (ScoreLeadJob is the
        // only caller, and only for ready leads), so BID is always yes here
        // — it's spelled out anyway since the bidder reads this message
        // standalone, without the code path behind it in view. BOOST is a
        // stricter bar (score 9-10): a plain threshold, not a separate AI
        // judgment call, flagging only the strongest fits as worth spending
        // extra Upwork Connects on.
        $boost = $score >= 9 ? 'yes' : 'no';

        $lines = [
            "🟢 *New ready lead*",
            $lead->title,
            (string) $lead->url,
            '',
            "SCORE: {$score}/10",
            'BID: yes',
            "BOOST: {$boost}",
            "Reason: {$lead->score_reason}",
            '',
            "Budget: {$lead->budget}",
            "Proposals so far: {$lead->proposal_count}",
            "Client: {$lead->client_country} · spend {$lead->client_spend} · hire rate {$lead->client_hire_rate}"
                .($lead->payment_verified ? ' · payment verified' : ' · payment unverified'),
            '',
            'Full brief:',
            '—',
            (string) $lead->full_brief,
            '—',
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
