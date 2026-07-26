<?php

namespace App\Services\Ai;

use App\Services\SettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Direct POST to the Anthropic Messages API — no SDK, no OpenClaw, no
 * intermediate hop. The system prompt goes in a cache_control ephemeral
 * block (static content first, per-call job brief last), so every call
 * after the first reads the long rules block at ~0.1x input price.
 *
 * Note: caching silently doesn't engage below the model's minimum
 * cacheable prefix (4096 tokens on Haiku 4.5 / Opus 4.8, 2048 on
 * Sonnet-tier) — usage.cache_read_input_tokens stays 0 until the system
 * prompt is long enough. No error either way.
 */
class AnthropicProvider implements AiProvider
{
    /**
     * USD per million tokens [input, output], verified against the model
     * catalog (cached 2026-06-24). Cache write bills 1.25x input (5m TTL),
     * cache read bills 0.1x input.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    protected const PRICES = [
        'claude-haiku-4-5' => [1.00, 5.00],
        'claude-sonnet-4-6' => [3.00, 15.00],
        'claude-opus-4-8' => [5.00, 25.00],
    ];

    // Sonnet 5 has introductory pricing through 2026-08-31.
    protected const SONNET_5_INTRO_UNTIL = '2026-08-31';

    public const MODELS = ['claude-haiku-4-5', 'claude-sonnet-5', 'claude-sonnet-4-6', 'claude-opus-4-8'];

    public function __construct(protected SettingsService $settings) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function isConfigured(): bool
    {
        return filled($this->settings->platform('anthropic_api_key'));
    }

    public function complete(string $systemPrompt, string $userContent, string $model, int $maxTokens): AiResponse
    {
        $startedAt = microtime(true);

        // Deliberately minimal request body: no temperature/thinking/effort,
        // so the same shape is valid on every model in the dropdown (Sonnet 5
        // and Opus 4.8 reject sampling params outright).
        $response = Http::withHeaders([
            'x-api-key' => (string) $this->settings->platform('anthropic_api_key'),
            'anthropic-version' => '2023-06-01',
        ])
            ->acceptJson()
            ->timeout(120)
            // Same reasoning as OpenAiProvider: a 429 is usually a
            // self-clearing token-bucket limit, worth retrying before it
            // counts as a real failure toward AiManager's failover.
            ->retry([3000, 8000, 20000], 0, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->status() === 429))
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => [
                    [
                        'type' => 'text',
                        'text' => $systemPrompt,
                        'cache_control' => ['type' => 'ephemeral'],
                    ],
                ],
                'messages' => [
                    ['role' => 'user', 'content' => $userContent],
                ],
            ]);

        $response->throw();

        $data = (array) $response->json();

        // Sonnet 5 runs adaptive thinking by default — the text block is not
        // necessarily content[0], so pick the first text block explicitly.
        $text = '';
        foreach ((array) ($data['content'] ?? []) as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text = (string) $block['text'];
                break;
            }
        }

        if (($data['stop_reason'] ?? null) === 'refusal' || $text === '') {
            throw new \RuntimeException(
                'Anthropic returned no text (stop_reason: '.($data['stop_reason'] ?? 'unknown').').'
            );
        }

        $usage = (array) ($data['usage'] ?? []);
        $input = (int) ($usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? 0);
        $cacheWrite = (int) ($usage['cache_creation_input_tokens'] ?? 0);

        return new AiResponse(
            text: $text,
            provider: $this->name(),
            model: $model,
            inputTokens: $input,
            outputTokens: $output,
            cachedTokens: $cacheRead,
            cacheWriteTokens: $cacheWrite,
            costUsd: $this->cost($model, $input, $output, $cacheRead, $cacheWrite),
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }

    protected function cost(string $model, int $input, int $output, int $cacheRead, int $cacheWrite): ?float
    {
        $prices = self::PRICES[$model] ?? null;

        if ($model === 'claude-sonnet-5') {
            $prices = now()->toDateString() <= self::SONNET_5_INTRO_UNTIL ? [2.00, 10.00] : [3.00, 15.00];
        }

        if (! $prices) {
            return null;
        }

        [$in, $out] = $prices;

        return round(
            ($input * $in + $cacheWrite * $in * 1.25 + $cacheRead * $in * 0.10 + $output * $out) / 1_000_000,
            6,
        );
    }
}
