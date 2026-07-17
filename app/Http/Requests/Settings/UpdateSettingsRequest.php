<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accepts a partial payload — only keys present are saved, so each Settings
 * section (Vollna / AI Engine (OpenClaw) / WhatsApp / Rules) can "Save" on
 * its own.
 */
class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Branding
            'app_name' => ['sometimes', 'nullable', 'string', 'max:100'],

            // Vollna
            'vollna_webhook_secret' => ['sometimes', 'nullable', 'string', 'max:500'],
            'vollna_api_token' => ['sometimes', 'nullable', 'string', 'max:500'],
            'vollna_filter_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'vollna_silence_alert_hours' => ['sometimes', 'integer', 'min:1', 'max:72'],

            // AI engine (OpenClaw)
            'openclaw_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'openclaw_token' => ['sometimes', 'nullable', 'string', 'max:500'],
            'ai_engine_enabled' => ['sometimes', 'boolean'],

            // WhatsApp — sent via OpenClaw, no Meta token/phone ID needed
            'bidder_whatsapp' => ['sometimes', 'nullable', 'string', 'max:30'],

            // Mail (SMTP)
            'mail_host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mail_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'mail_username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mail_password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mail_encryption' => ['sometimes', 'nullable', 'string', 'in:tls,ssl,'],
            'mail_from_address' => ['sometimes', 'nullable', 'email', 'max:255'],
            'mail_from_name' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Rules
            'min_budget' => ['sometimes', 'integer', 'min:0'],
            'max_proposals' => ['sometimes', 'integer', 'min:1'],
            'score_cutoff' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'stack_keywords' => ['sometimes', 'array'],
            'stack_keywords.*' => ['string', 'max:50'],
            'hourly_floor' => ['sometimes', 'integer', 'min:0'],
            'zero_history_budget_floor' => ['sometimes', 'integer', 'min:0'],
            'red_flag_words' => ['sometimes', 'array'],
            'red_flag_words.*' => ['string', 'max:100'],
            'followup_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'max_posted_age_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
        ];
    }
}
