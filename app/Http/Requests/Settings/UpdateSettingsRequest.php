<?php

namespace App\Http\Requests\Settings;

use App\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Accepts a partial payload — only keys present are saved, so each Settings
 * section (Vollna / AI Engine (OpenClaw) / WhatsApp / Rules) can "Save" on
 * its own.
 */
class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The real gate is PER KEY in SettingsController::store() — every
        // posted key requires its own setting.{key} permission. This only
        // confirms the caller may see Settings at all.
        return (bool) $this->user()?->can(Permissions::SETTINGS_VIEW);
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

            // Vollna email intake (IMAP)
            'gmail_address' => ['sometimes', 'nullable', 'email', 'max:255'],
            'gmail_app_password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'imap_host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'imap_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'imap_folder' => ['sometimes', 'nullable', 'string', 'max:100'],
            'vollna_sender_filter' => ['sometimes', 'nullable', 'string', 'max:255'],
            'imap_poll_enabled' => ['sometimes', 'boolean'],

            // AI engine (OpenClaw)
            'openclaw_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'openclaw_token' => ['sometimes', 'nullable', 'string', 'max:500'],
            'ai_engine_enabled' => ['sometimes', 'boolean'],
            'proposal_writing_enabled' => ['sometimes', 'boolean'],

            // Direct AI layer — ai_provider, the two API keys and the three
            // model IDs are ABSENT ON PURPOSE (P8). They are platform-only
            // now: the platform pays for AI out of pooled keys, and a
            // workspace neither supplies a key nor chooses which model its
            // pooled spend goes to. A key posted here is not validated, not
            // saved, and not silently dropped-but-acknowledged — it simply
            // isn't in the accepted vocabulary, and SettingsService throws on
            // any attempt to write a tenant override for one.
            //
            // Likewise scoring_system_prompt / proposal_skill /
            // stage_2_scoring_addendum: the METHODOLOGY is the product, edited
            // by the platform owner in the platform console, identical for
            // every workspace.
            'anthropic_funded_total' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'openai_funded_total' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'account_stage' => ['sometimes', 'string', 'in:stage_1_new,stage_2_established'],
            // project_facts is the workspace's own track record — its data,
            // its to edit. proposal_reference is operator reading and never
            // enters a prompt.
            'proposal_reference' => ['sometimes', 'nullable', 'string', 'max:150000'],
            'project_facts' => ['sometimes', 'nullable', 'string', 'max:20000'],

            // Proposal quality gate (mechanical lint config)
            'proposal_quality_gate' => ['sometimes', 'boolean'],
            'proposal_min_words' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'proposal_max_words' => ['sometimes', 'integer', 'min:0', 'max:2000'],
            'proposal_signature' => ['sometimes', 'nullable', 'string', 'max:50'],
            'proposal_required_phrases' => ['sometimes', 'array'],
            'proposal_required_phrases.*' => ['string', 'max:100'],
            'proposal_banned_phrases' => ['sometimes', 'array'],
            'proposal_banned_phrases.*' => ['string', 'max:100'],

            // WhatsApp — sent via OpenClaw, no Meta token/phone ID needed
            'bidder_whatsapp' => ['sometimes', 'nullable', 'string', 'max:30'],
            'whatsapp_alert_mode' => ['sometimes', Rule::in(['normal', 'paused', 'muted'])],

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
            'core_stacks' => ['sometimes', 'array'],
            'core_stacks.*' => ['string', 'max:50'],
            'secondary_stacks' => ['sometimes', 'array'],
            'secondary_stacks.*' => ['string', 'max:50'],
            'excluded_stacks' => ['sometimes', 'array'],
            'excluded_stacks.*' => ['string', 'max:50'],
            'priority_decay_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            // Empty disables the ping. Anything else must be a real http(s)
            // URL: the scheduler and the "Send test ping" button both dial
            // this value out, so a typo'd or non-web scheme should be caught
            // at save time rather than failing silently once a minute.
            'heartbeat_ping_url' => ['sometimes', 'nullable', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail) {
                if (trim((string) $value) === '') {
                    return;
                }

                $scheme = strtolower((string) parse_url(trim((string) $value), PHP_URL_SCHEME));

                if (! in_array($scheme, ['http', 'https'], true)) {
                    $fail('The heartbeat ping URL must be a full http:// or https:// URL.');
                }
            }],
            'hourly_floor' => ['sometimes', 'integer', 'min:0'],
            'zero_history_budget_floor' => ['sometimes', 'integer', 'min:0'],
            'red_flag_words' => ['sometimes', 'array'],
            'red_flag_words.*' => ['string', 'max:100'],
            'followup_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'notification_freshness_hours' => ['sometimes', 'integer', 'min:0', 'max:168'],
            'notify_score_min' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'quiet_hours_start' => ['sometimes', 'integer', 'min:0', 'max:23'],
            'quiet_hours_end' => ['sometimes', 'integer', 'min:0', 'max:23'],
            'whatsapp_cloud_enabled' => ['sometimes', 'boolean'],
            'whatsapp_phone_number_id' => ['sometimes', 'string', 'max:64'],
            'whatsapp_waba_id' => ['sometimes', 'string', 'max:64'],
            'whatsapp_access_token' => ['sometimes', 'string', 'max:1024'],
            'whatsapp_app_secret' => ['sometimes', 'string', 'max:255'],
            'whatsapp_verify_token' => ['sometimes', 'string', 'max:255'],
            'whatsapp_template_name' => ['sometimes', 'string', 'max:128'],
            'whatsapp_template_language' => ['sometimes', 'string', 'max:16'],
            'max_posted_age_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
        ];
    }
}
