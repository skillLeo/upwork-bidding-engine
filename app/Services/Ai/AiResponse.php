<?php

namespace App\Services\Ai;

/**
 * Normalized result of one completion call, whichever provider ran it.
 * Token figures are the API's own usage numbers; cost is computed from
 * those figures and the provider's published per-model prices.
 */
final class AiResponse
{
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $cachedTokens,
        public readonly int $cacheWriteTokens,
        public readonly ?float $costUsd,
        public readonly int $durationMs,
    ) {}
}
