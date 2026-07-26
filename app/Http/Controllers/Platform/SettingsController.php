<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Platform settings (P5): signup_mode, the platform's default prompts, plan
 * display metadata, and "global AI model default" guidance. Every key here
 * is declared platform_only in config/tenancy.php, so SettingsService
 * already redirects the write to the platform row (tenant_id null) as long
 * as this runs under the platform-owning workspace — which /platform does on
 * this single-domain deployment, the same way the pre-existing platform-only
 * keys (signup_mode, scoring_system_prompt, ...) already worked before P5.
 */
class SettingsController extends Controller
{
    private const KEYS = [
        'signup_mode',
        'scoring_system_prompt',
        'proposal_skill',
        'stage_2_scoring_addendum',
        'platform_default_scoring_model',
        'platform_default_proposal_model',
        'platform_default_review_model',
        'platform_plan_definitions',
        'google_oauth_client_id',
        'google_oauth_client_secret',
        // P8 — the pooled AI credentials and the models they may be spent on.
        // These left tenant custody entirely; this screen is now the only
        // place in the product where they can be read or written.
        'ai_provider',
        'anthropic_api_key',
        'openai_api_key',
        'scoring_model',
        'proposal_model',
        'review_model',
        // P8 — SMTP is deployment infrastructure, not a customer's setting,
        // and was already platform_only. It moved here from the tenant
        // Settings screen along with everything else a workspace may not see.
        'mail_host',
        'mail_port',
        'mail_username',
        'mail_password',
        'mail_encryption',
        'mail_from_address',
        'mail_from_name',
    ];

    /** Write-only from this screen: shown as is_set, never echoed back. */
    private const SECRETS = [
        'google_oauth_client_secret',
        'anthropic_api_key',
        'openai_api_key',
        'mail_username',
        'mail_password',
    ];

    public function show(SettingsService $settings): JsonResponse
    {
        // Read through platform() for the keys that have a platform-only
        // contract, so the value shown here is provably the one every
        // workspace's calls actually use — not whatever the console's own
        // bound tenant happens to resolve to.
        $data = collect(self::KEYS)->mapWithKeys(fn (string $k) => [$k => $settings->platform($k)])->all();

        // Secrets are write-only from here on, same masking convention as the
        // tenant Settings screen's secret fields.
        foreach (self::SECRETS as $key) {
            $data[$key] = ['is_set' => filled($settings->platform($key))];
        }

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, SettingsService $settings): JsonResponse
    {
        $validated = $request->validate([
            'signup_mode' => ['sometimes', 'string', 'in:open,invite_code,closed'],
            'scoring_system_prompt' => ['sometimes', 'string'],
            'proposal_skill' => ['sometimes', 'string'],
            'stage_2_scoring_addendum' => ['sometimes', 'string'],
            'platform_default_scoring_model' => ['sometimes', 'string', 'max:120'],
            'platform_default_proposal_model' => ['sometimes', 'string', 'max:120'],
            'platform_default_review_model' => ['sometimes', 'string', 'max:120'],
            'platform_plan_definitions' => ['sometimes', 'array'],
            'platform_plan_definitions.*.key' => ['required_with:platform_plan_definitions', 'string', 'max:40'],
            'platform_plan_definitions.*.label' => ['required_with:platform_plan_definitions', 'string', 'max:80'],
            'platform_plan_definitions.*.lead_cap' => ['nullable', 'integer', 'min:0'],
            'platform_plan_definitions.*.notes' => ['nullable', 'string', 'max:500'],
            'google_oauth_client_id' => ['sometimes', 'string', 'max:255'],
            'google_oauth_client_secret' => ['sometimes', 'nullable', 'string', 'max:255'],

            // P8 — pooled AI credentials and models.
            'ai_provider' => ['sometimes', 'string', 'in:anthropic,openai'],
            'anthropic_api_key' => ['sometimes', 'nullable', 'string', 'max:300'],
            'openai_api_key' => ['sometimes', 'nullable', 'string', 'max:300'],
            'scoring_model' => ['sometimes', 'string', 'max:100'],
            'proposal_model' => ['sometimes', 'string', 'max:100'],
            'review_model' => ['sometimes', 'string', 'max:100'],

            // P8 — SMTP for the whole deployment.
            'mail_host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mail_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'mail_username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mail_password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mail_encryption' => ['sometimes', 'nullable', 'string', 'in:tls,ssl,'],
            'mail_from_address' => ['sometimes', 'nullable', 'email', 'max:255'],
            'mail_from_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Never overwrite a stored secret with a blank resubmit — same rule
        // SettingsController::store() uses for every other secret field.
        foreach (self::SECRETS as $key) {
            if (array_key_exists($key, $validated) && trim((string) $validated[$key]) === '') {
                unset($validated[$key]);
            }
        }

        // Explicitly the platform layer, not "whichever layer the bound
        // tenant implies" — see SettingsService::setManyOnPlatformLayer.
        $settings->setManyOnPlatformLayer($validated);

        return response()->json(['data' => ['message' => 'Platform settings saved.']]);
    }

    /**
     * Prove the deployment's SMTP credentials actually deliver.
     *
     * Moved here with the mail_* keys themselves (P8). Worth keeping rather
     * than dropping: this app once went months sending nothing at all because
     * the only signal of a broken mail config was silence.
     */
    public function testMail(Request $request, SettingsService $settings): JsonResponse
    {
        $to = $request->user()?->email;

        if (! $settings->platform('mail_host')) {
            return response()->json(['data' => ['success' => false, 'message' => 'No SMTP host configured.']]);
        }

        try {
            Mail::raw(
                'SkillLeo test email — your SMTP settings are working.',
                fn ($message) => $message->to($to)->subject('SkillLeo — test email'),
            );

            return response()->json(['data' => ['success' => true, 'message' => "Test email sent to {$to}."]]);
        } catch (\Throwable $e) {
            // The raw exception can echo connection details (host, port,
            // sometimes auth hints). Log it, keep the response generic.
            report($e);

            return response()->json(['data' => [
                'success' => false,
                'message' => 'Could not send test email — check the SMTP host, port, and credentials, then check the logs.',
            ]]);
        }
    }
}
