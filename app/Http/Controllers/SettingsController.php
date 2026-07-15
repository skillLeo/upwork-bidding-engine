<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\ActivityLog;
use App\Services\OpenClawService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(protected SettingsService $settings) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->maskedPayload()]);
    }

    public function store(UpdateSettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $toSave = [];

        foreach ($validated as $key => $value) {
            $meta = SettingsService::SCHEMA[$key] ?? null;

            if (! $meta) {
                continue;
            }

            // Blank secret input means "leave the existing value alone" —
            // we never overwrite a stored key with an empty string.
            if ($meta['secret'] && (! is_string($value) || trim($value) === '')) {
                continue;
            }

            $toSave[$key] = $value;
        }

        $this->settings->setMany($toSave);

        ActivityLog::record(
            ActivityType::SettingUpdated,
            meta: ['keys' => array_keys($toSave)],
            userId: $request->user()?->id,
        );

        return response()->json([
            'data' => $this->maskedPayload(),
            'meta' => ['message' => 'Settings saved.'],
        ]);
    }

    public function testConnection(
        string $service,
        Request $request,
        OpenClawService $openClaw,
    ): JsonResponse {
        if (! in_array($service, SettingsService::SERVICES, true)) {
            return response()->json(['message' => 'Unknown service.'], 422);
        }

        $result = match ($service) {
            'openclaw' => $openClaw->testConnection(),
            'whatsapp' => $openClaw->testWhatsAppConnection(),
            'vollna' => $this->testVollna(),
        };

        ActivityLog::record(
            ActivityType::ConnectionTested,
            meta: ['service' => $service, ...$result],
            userId: $request->user()?->id,
        );

        return response()->json(['data' => $result]);
    }

    /**
     * Vollna only ever calls us (webhook), so there is nothing to dial out
     * to — "testing" this just confirms the shared secret is configured.
     *
     * @return array{success: bool, message: string}
     */
    protected function testVollna(): array
    {
        $secret = $this->settings->vollnaWebhookSecret();

        if (! $secret || strlen($secret) < 8) {
            return ['success' => false, 'message' => 'No webhook secret configured (or it looks too short).'];
        }

        return ['success' => true, 'message' => 'Webhook secret is set. Vollna connects to you — point its webhook at /api/vollna-hook.'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function maskedPayload(): array
    {
        $all = $this->settings->all();
        $grouped = [];

        foreach (SettingsService::SCHEMA as $key => $meta) {
            $value = $all[$key] ?? null;

            $grouped[$meta['group']][$key] = $meta['secret']
                ? ['is_set' => filled($value), 'masked' => $this->mask($value)]
                : $value;
        }

        return $grouped;
    }

    protected function mask(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $length = strlen($value);

        if ($length <= 4) {
            return str_repeat('•', 8);
        }

        return str_repeat('•', 8).substr($value, -4);
    }
}
