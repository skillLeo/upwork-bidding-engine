<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Lead;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the external OpenClaw service, which is the thing that actually
 * calls Claude to score jobs and draft proposals/replies. We pass along the
 * Claude key + model from Settings on every call so OpenClaw never needs
 * its own copy of the secret.
 */
class OpenClawService
{
    public function __construct(protected SettingsService $settings) {}

    protected function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->settings->openClawUrl(), '/'))
            ->withToken((string) $this->settings->openClawToken())
            ->acceptJson()
            ->timeout(45)
            // Array form: each element is the delay (ms) before that attempt,
            // so this is 3 retries at 1s / 3s / 6s — real backoff, not fixed.
            ->retry([1000, 3000, 6000], 0, function (\Exception $exception, PendingRequest $request) {
                return $exception instanceof ConnectionException;
            });
    }

    protected function aiConfig(): array
    {
        return [
            'provider' => 'anthropic',
            'api_key' => $this->settings->claudeApiKey(),
            'model' => $this->settings->claudeModel(),
        ];
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
            'ai' => $this->aiConfig(),
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
            'ai' => $this->aiConfig(),
        ]);

        $response->throw();

        $data = (array) $response->json();

        return [
            'reply' => (string) ($data['reply'] ?? ''),
            'needs_hassam' => (bool) ($data['needs_hassam'] ?? false),
        ];
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
