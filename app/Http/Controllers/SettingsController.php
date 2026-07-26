<?php

namespace App\Http\Controllers;

use App\Authorization\Permissions;
use App\Enums\ActivityType;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\ActivityLog;
use App\Services\OpenClawService;
use App\Services\SettingsService;
use App\Services\WhatsApp\WhatsAppCloudService;
use App\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    public function __construct(protected SettingsService $settings) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->maskedPayload($request)]);
    }

    public function store(UpdateSettingsRequest $request): JsonResponse
    {
        $this->refusePlatformOnlyKeys($request);

        $validated = $request->validated();
        $user = $request->user();
        $toSave = [];

        foreach ($validated as $key => $value) {
            $meta = SettingsService::SCHEMA[$key] ?? null;

            if (! $meta) {
                continue;
            }

            // ULTRA-GRANULAR: every key is its own permission
            // (setting.{key}), so a role or an individual user can be allowed
            // to change exactly this field and nothing else. A key the caller
            // doesn't hold is a hard 403 NAMING the key — never a silent drop
            // that looks like a save that worked.
            if (! $user?->can(Permissions::forSettingKey($key))) {
                abort(403, "You do not have permission to change [{$key}].");
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
            'data' => $this->maskedPayload($request),
            'meta' => ['message' => 'Settings saved.'],
        ]);
    }

    /**
     * Refuse a platform-only key OUT LOUD instead of quietly ignoring it.
     *
     * These keys were removed from UpdateSettingsRequest's accepted
     * vocabulary in P8, which made them safe — Laravel's validated() drops
     * anything it doesn't know, so nothing was ever written. But the request
     * still came back 200 "Settings saved.", which is the exact failure this
     * codebase refuses everywhere else: a save that looks like it worked and
     * didn't. Someone posting scoring_system_prompt deserves to be told the
     * methodology belongs to the platform, not to be left believing their
     * workspace now has its own rubric.
     *
     * The platform-OWNING workspace is exempt: its writes are redirected to
     * the platform layer by SettingsService, which is how the platform owner
     * edits the product's own defaults at all.
     */
    private function refusePlatformOnlyKeys(Request $request): void
    {
        if (Tenancy::current()?->plan === 'internal') {
            return;
        }

        $platformOnly = (array) config('tenancy.platform_only_keys', []);
        $attempted = array_values(array_intersect(array_keys($request->all()), $platformOnly));

        if ($attempted === []) {
            return;
        }

        ActivityLog::record('platform_only_setting_refused', meta: [
            'keys' => $attempted,
        ], userId: $request->user()?->id);

        abort(403, count($attempted) === 1
            ? "[{$attempted[0]}] belongs to the platform, not to a workspace — it is the same for every workspace and only the platform owner can change it."
            : 'These belong to the platform, not to a workspace: '.implode(', ', $attempted).'.');
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
            // Public, non-secret hints the sign-in / sign-up screens need to
            // render the right state before anyone is authenticated: whether
            // self-serve sign-up is open, and whether "Continue with Google"
            // should appear at all (only the presence of a client id is
            // exposed — never the id or secret itself).
            'signup_mode' => (string) $this->settings->get('signup_mode', 'invite_code'),
            'google_enabled' => (bool) $this->settings->get('google_oauth_client_id'),
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
        $token = Str::random(48);
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

        $path = $this->storeOptimizedLogo($request->file('logo'));
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

    /**
     * The logo only ever renders at 32x32 in the nav bar, but uploads have
     * arrived as full camera-resolution JPEGs (one was 4500x4500, 423KB) -
     * loaded on every single page. Resized to fit 512x512 via GD (already
     * present on this host, no new dependency) rather than trusting upload
     * size to stay reasonable.
     */
    private function storeOptimizedLogo(UploadedFile $file): string
    {
        $maxDimension = 512;
        $info = getimagesize($file->getRealPath());
        [$width, $height] = $info;
        $type = $info[2];
        $extension = match ($type) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => 'jpg',
        };
        $filename = 'branding/'.Str::random(40).'.'.$extension;

        if ($width <= $maxDimension && $height <= $maxDimension) {
            Storage::disk('public')->put($filename, file_get_contents($file->getRealPath()));

            return $filename;
        }

        $source = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($file->getRealPath()),
            IMAGETYPE_PNG => imagecreatefrompng($file->getRealPath()),
            IMAGETYPE_WEBP => imagecreatefromwebp($file->getRealPath()),
            default => null,
        };

        if (! $source) {
            Storage::disk('public')->put($filename, file_get_contents($file->getRealPath()));

            return $filename;
        }

        $scale = $maxDimension / max($width, $height);
        $newWidth = (int) round($width * $scale);
        $newHeight = (int) round($height * $scale);

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        match ($type) {
            IMAGETYPE_PNG => imagepng($resized, null, 6),
            IMAGETYPE_WEBP => imagewebp($resized, null, 85),
            default => imagejpeg($resized, null, 85),
        };
        $contents = ob_get_clean();

        imagedestroy($source);
        imagedestroy($resized);

        Storage::disk('public')->put($filename, $contents);

        return $filename;
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
            // Prefer Meta's Cloud API when it's configured — that's the path
            // real alerts take, so it's the one worth proving.
            'whatsapp' => app(WhatsAppCloudService::class)->isConfigured()
                ? app(WhatsAppCloudService::class)->testConnection()
                : $openClaw->testWhatsAppConnection(),
            'vollna' => $this->testVollna(),
            'mail' => $this->testMail($request),
            'heartbeat' => $this->testHeartbeat(),
            'anthropic' => $this->testAiProvider(
                'https://api.anthropic.com/v1/models',
                ['x-api-key' => (string) $this->settings->platform('anthropic_api_key'), 'anthropic-version' => '2023-06-01'],
                filled($this->settings->platform('anthropic_api_key')),
            ),
            'openai' => $this->testAiProvider(
                'https://api.openai.com/v1/models',
                ['Authorization' => 'Bearer '.$this->settings->platform('openai_api_key')],
                filled($this->settings->platform('openai_api_key')),
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
            $response = Http::withHeaders($headers)->timeout(15)->get($url);

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
    /**
     * Fires ONE real ping at the saved heartbeat URL and reports the HTTP
     * status inline, so the monitor can be confirmed green from this screen
     * instead of pasting a URL, waiting for the next scheduler tick, and
     * then going to read the monitor's own dashboard to find out.
     *
     * Reads the SAVED value, like every other test here — an unsaved field
     * would test a URL the scheduler is not actually going to ping.
     *
     * @return array{success: bool, message: string}
     */
    protected function testHeartbeat(): array
    {
        $url = trim((string) $this->settings->get('heartbeat_ping_url', ''));

        if ($url === '') {
            return ['success' => false, 'message' => 'No heartbeat URL saved yet — save the rules first, then test.'];
        }

        // Slightly longer than the scheduler's own 3s budget: this one is a
        // human waiting on a button, not a tick that must not be delayed.
        try {
            $response = Http::timeout(5)->connectTimeout(3)->get($url);

            if ($response->successful()) {
                return ['success' => true, 'message' => "Ping delivered — monitor answered HTTP {$response->status()}."];
            }

            return ['success' => false, 'message' => "Monitor answered HTTP {$response->status()} — check the ping URL."];
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'message' => 'Could not reach the monitor — check the URL is correct and publicly reachable.'];
        }
    }

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
    protected function maskedPayload(?Request $request = null): array
    {
        $all = $this->settings->all();
        $grouped = [];

        // A secret key's entry is visible (masked) only to a caller holding
        // that key's own setting.{key} permission — ABSENT from the body
        // otherwise, not masked in it. Omission is the honest contract:
        // masking would still ship the shape and invite the assumption the
        // value is merely hidden.
        $user = $request?->user();

        // PLATFORM-ONLY KEYS ARE ABSENT FROM THE TENANT PAYLOAD ENTIRELY (P8).
        // The pooled AI credentials and model IDs, the scoring rubric and
        // drafting skill, the SMTP credentials, signup_mode and the plan
        // catalog are the platform's, not the workspace's — a workspace owner
        // cannot see or edit them, and they are edited in the platform
        // console instead. Omitted rather than shown read-only for the same
        // reason a secret they lack permission for is omitted: shipping the
        // shape invites the assumption the value is merely hidden.
        //
        // THE PLATFORM-OWNING WORKSPACE IS EXEMPT, and has to be: writes from
        // it are already redirected to the platform layer by
        // SettingsService::writeTenantId(), so this is where the platform
        // owner actually edits these. Omitting them here as well would leave
        // the lead-source credentials editable from nowhere at all.
        $platformOnly = Tenancy::current()?->plan === 'internal'
            ? []
            : (array) config('tenancy.platform_only_keys', []);

        foreach (SettingsService::SCHEMA as $key => $meta) {
            $value = $all[$key] ?? null;

            if (in_array($key, $platformOnly, true)) {
                continue;
            }

            if ($meta['secret'] && ! $user?->can(Permissions::forSettingKey($key))) {
                continue;
            }

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
