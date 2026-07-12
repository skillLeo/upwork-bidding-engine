<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Direct Anthropic connectivity check for the Settings screen only.
 * In production the actual scoring/writing call to Claude is made by the
 * external OpenClaw service — we hand it the stored key/model so it can
 * call Claude on our behalf (see OpenClawService::scoreAndWrite()).
 */
class ClaudeService
{
    public function __construct(protected SettingsService $settings) {}

    /**
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        $key = $this->settings->claudeApiKey();

        if (! $key) {
            return ['success' => false, 'message' => 'No Claude API key configured.'];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
            ])->timeout(10)->get('https://api.anthropic.com/v1/models');

            if ($response->successful()) {
                return ['success' => true, 'message' => 'Connected — Claude API key is valid.'];
            }

            if ($response->status() === 401) {
                return ['success' => false, 'message' => 'Claude API rejected the key (401 unauthorized).'];
            }

            return ['success' => false, 'message' => "Claude API responded with HTTP {$response->status()}."];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Could not reach the Claude API: '.$e->getMessage()];
        }
    }
}
