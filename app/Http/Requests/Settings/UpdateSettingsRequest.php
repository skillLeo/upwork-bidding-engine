<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accepts a partial payload — only keys present are saved, so each Settings
 * section (AI / Vollna / OpenClaw / WhatsApp / Rules) can "Save" on its own.
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
            // AI
            'claude_api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'claude_model' => ['sometimes', 'string', 'max:100'],

            // Vollna
            'vollna_webhook_secret' => ['sometimes', 'nullable', 'string', 'max:500'],

            // OpenClaw
            'openclaw_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'openclaw_token' => ['sometimes', 'nullable', 'string', 'max:500'],

            // WhatsApp — sent via OpenClaw, no Meta token/phone ID needed
            'bidder_whatsapp' => ['sometimes', 'nullable', 'string', 'max:30'],

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
        ];
    }
}
