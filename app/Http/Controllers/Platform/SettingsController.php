<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    ];

    public function show(SettingsService $settings): JsonResponse
    {
        $data = collect(self::KEYS)->mapWithKeys(fn (string $k) => [$k => $settings->get($k)])->all();

        // The secret is write-only from here on, same masking convention as
        // the tenant Settings screen's secret fields.
        $data['google_oauth_client_secret'] = filled($settings->get('google_oauth_client_secret'))
            ? ['is_set' => true]
            : ['is_set' => false];

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
        ]);

        // Never overwrite a stored secret with a blank resubmit — same rule
        // SettingsController::store() uses for every other secret field.
        if (array_key_exists('google_oauth_client_secret', $validated) && $validated['google_oauth_client_secret'] === '') {
            unset($validated['google_oauth_client_secret']);
        }

        $settings->setMany($validated);

        return response()->json(['data' => ['message' => 'Platform settings saved.']]);
    }
}
