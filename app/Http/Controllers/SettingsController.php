<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\ActivityLog;
use App\Services\OpenClawService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

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

    /**
     * Public — unauthenticated pages (sign-in, forgot/reset password) need
     * the product name and logo before anyone has a token.
     */
    public function branding(): JsonResponse
    {
        return response()->json(['data' => [
            'name' => $this->settings->appName(),
            'logo_url' => $this->settings->appLogoUrl(),
        ]]);
    }

    /**
     * Reveal the Agent API token so the operator can paste it into the
     * OpenClaw skill config. Admin-gated by the route group; the reveal
     * itself is logged.
     */
    public function revealAgentToken(): JsonResponse
    {
        ActivityLog::record('agent_token_revealed');

        return response()->json(['data' => [
            'token' => $this->settings->agentApiToken(),
        ]]);
    }

    /**
     * Mint a fresh Agent API token (invalidating the old one instantly)
     * and return it once for copying.
     */
    public function regenerateAgentToken(): JsonResponse
    {
        $token = \Illuminate\Support\Str::random(48);
        $this->settings->set('agent_api_token', $token);

        ActivityLog::record('agent_token_regenerated');

        return response()->json(['data' => ['token' => $token]]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            // Raster-only, same reasoning as avatar uploads: SVG can carry
            // an inline <script> and execute if its stored URL is ever
            // opened directly.
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $oldPath = $this->settings->get('app_logo_path');
        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        $path = $request->file('logo')->store('branding', 'public');
        $this->settings->set('app_logo_path', $path);

        ActivityLog::record(
            ActivityType::SettingUpdated,
            meta: ['keys' => ['app_logo_path']],
            userId: $request->user()?->id,
        );

        return response()->json(['data' => [
            'name' => $this->settings->appName(),
            'logo_url' => $this->settings->appLogoUrl(),
        ]]);
    }

    public function removeLogo(Request $request): JsonResponse
    {
        $oldPath = $this->settings->get('app_logo_path');
        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        $this->settings->set('app_logo_path', null);

        ActivityLog::record(
            ActivityType::SettingUpdated,
            meta: ['keys' => ['app_logo_path'], 'action' => 'logo_removed'],
            userId: $request->user()?->id,
        );

        return response()->json(['data' => [
            'name' => $this->settings->appName(),
            'logo_url' => null,
        ]]);
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
            'mail' => $this->testMail($request),
            'anthropic' => $this->testAiProvider(
                'https://api.anthropic.com/v1/models',
                ['x-api-key' => (string) $this->settings->get('anthropic_api_key'), 'anthropic-version' => '2023-06-01'],
                filled($this->settings->get('anthropic_api_key')),
            ),
            'openai' => $this->testAiProvider(
                'https://api.openai.com/v1/models',
                ['Authorization' => 'Bearer '.$this->settings->get('openai_api_key')],
                filled($this->settings->get('openai_api_key')),
            ),
        };

        ActivityLog::record(
            ActivityType::ConnectionTested,
            meta: ['service' => $service, ...$result],
            userId: $request->user()?->id,
        );

        return response()->json(['data' => $result]);
    }

    /**
     * Cheapest possible auth check for an AI provider: GET /v1/models
     * costs no tokens and fails fast on a bad/revoked key.
     *
     * @param  array<string, string>  $headers
     * @return array{success: bool, message: string}
     */
    protected function testAiProvider(string $url, array $headers, bool $keySet): array
    {
        if (! $keySet) {
            return ['success' => false, 'message' => 'No API key configured yet.'];
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders($headers)->timeout(15)->get($url);

            if ($response->successful()) {
                return ['success' => true, 'message' => 'API key works — models endpoint responded OK.'];
            }

            return ['success' => false, 'message' => "Provider responded with HTTP {$response->status()} — check the API key."];
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'message' => 'Could not reach the provider.'];
        }
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
     * Sends a real test email to the admin running the check — the only
     * way to prove SMTP creds actually deliver, not just that they're set.
     *
     * @return array{success: bool, message: string}
     */
    protected function testMail(Request $request): array
    {
        $to = $request->user()?->email;

        if (! $this->settings->mailConfig()['host']) {
            return ['success' => false, 'message' => 'No SMTP host configured.'];
        }

        if (! $to) {
            return ['success' => false, 'message' => 'No email address to send the test to.'];
        }

        try {
            Mail::raw('SkillLeo test email — your SMTP settings are working.', function ($message) use ($to) {
                $message->to($to)->subject('SkillLeo — test email');
            });

            return ['success' => true, 'message' => "Test email sent to {$to}."];
        } catch (\Throwable $e) {
            // The raw exception can echo back connection details (host,
            // port, sometimes auth hints) - log the real error server-side
            // and keep the client-facing message generic, even though this
            // is already an admin-only route.
            report($e);

            return ['success' => false, 'message' => 'Could not send test email — check the SMTP host, port, and credentials, then check the logs for details.'];
        }
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

        // Derived, not stored — the Settings UI needs a ready-to-render
        // logo URL, not the raw storage path.
        $grouped['branding']['app_logo_url'] = $this->settings->appLogoUrl();

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
