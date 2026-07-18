<?php

namespace App\Services\Ai;

interface AiProvider
{
    /**
     * Run one completion. $systemPrompt is the long, byte-identical block
     * (cached by the provider where supported); $userContent is the
     * per-call variable part and must come last.
     *
     * @throws \Throwable on any transport or API error — the caller
     *                    (AiManager) owns retries, failover, and logging.
     */
    public function complete(string $systemPrompt, string $userContent, string $model, int $maxTokens): AiResponse;

    public function name(): string;

    public function isConfigured(): bool;
}
