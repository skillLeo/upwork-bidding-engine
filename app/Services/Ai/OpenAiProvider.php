<?php

namespace App\Services\Ai;

use App\Services\SettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI chat completions — the failover lane, normally idle. OpenAI
 * caches prompt prefixes automatically (no cache_control equivalent);
 * cached prompt tokens are reported in prompt_tokens_details.cached_tokens
 * and bill at 50% of input.
 */
class OpenAiProvider implements AiProvider
{
    /**
     * USD per million tokens [input, output]. Only the two stable IDs we
     * suggest in Settings — an unknown/custom model still works, its cost
     * just logs as null (unknown) rather than a made-up number.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    protected const PRICES = [
        'gpt-4o-mini' => [0.15, 0.60],
        'gpt-4o' => [2.50, 10.00],
    ];

    public const MODELS = ['gpt-4o-mini', 'gpt-4o'];

    public function __construct(protected SettingsService $settings) {}

    public function name(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return filled($this->settings->platform('openai_api_key'));
    }

    public function complete(string $systemPrompt, string $userContent, string $model, int $maxTokens): AiResponse
    {
        $startedAt = microtime(true);

        $response = Http::withToken((string) $this->settings->platform('openai_api_key'))
            ->acceptJson()
            ->timeout(120)
            // Live tokens-per-minute limits (verified: this org's gpt-4o tier
            // caps at 30k TPM) throw a 429 mid-burst — the same request
            // usually succeeds a few seconds later once the window rolls, so
            // this is worth three attempts before surfacing as a failure. A
            // 429 must never count toward AiManager's 3-strike failover on
            // its own; every other 4xx still fails on the first attempt.
            ->retry([3000, 8000, 20000], 0, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->status() === 429))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'max_completion_tokens' => $maxTokens,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userContent],
                ],
            ]);

        $response->throw();

        $data = (array) $response->json();
        $text = (string) ($data['choices'][0]['message']['content'] ?? '');

        if ($text === '') {
            throw new \RuntimeException('OpenAI returned an empty completion.');
        }

        $usage = (array) ($data['usage'] ?? []);
        $prompt = (int) ($usage['prompt_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? 0);
        $cached = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);

        return new AiResponse(
            text: $text,
            provider: $this->name(),
            model: $model,
            inputTokens: $prompt,
            outputTokens: $completion,
            cachedTokens: $cached,
            cacheWriteTokens: 0,
            costUsd: $this->cost($model, $prompt, $completion, $cached),
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }

    protected function cost(string $model, int $prompt, int $completion, int $cached): ?float
    {
        $prices = self::PRICES[$model] ?? null;

        if (! $prices) {
            return null;
        }

        [$in, $out] = $prices;

        return round(
            (($prompt - $cached) * $in + $cached * $in * 0.50 + $completion * $out) / 1_000_000,
            6,
        );
    }
}
