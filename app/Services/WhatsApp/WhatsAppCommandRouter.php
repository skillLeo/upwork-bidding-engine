<?php

namespace App\Services\WhatsApp;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Services\SettingsService;

/**
 * Turns an inbound WhatsApp text into an action on the newest alerted lead.
 *
 * Scope is deliberately narrow. This is a structured, single-purpose
 * operations bot for the workspace owner's own number — lead triage and
 * nothing else. It is NOT a general-purpose assistant, which is both the
 * right product decision and what keeps the integration inside Meta's
 * WhatsApp Business terms (open-ended AI assistants as the primary function
 * are prohibited; a business running a bot to serve its own operations is
 * explicitly fine).
 *
 * Unrecognised text gets a short menu rather than being forwarded to an LLM,
 * so there is no path by which this becomes an open chatbot by accident.
 */
class WhatsAppCommandRouter
{
    public function __construct(protected SettingsService $settings) {}

    /**
     * @return string the reply to send back, or '' to stay silent
     */
    public function handle(string $rawText): string
    {
        $text = trim(mb_strtolower($rawText));
        $command = preg_split('/\s+/', $text)[0] ?? '';

        return match ($command) {
            'bid', 'bidding', 'done' => $this->markNewest(LeadStatus::Sent, 'bid'),
            'skip', 'no', 'pass' => $this->markNewest(LeadStatus::Archived, 'skipped'),
            'pause' => $this->setMode('paused', 'Reminders paused. New lead alerts still arrive. Reply RESUME to turn them back on.'),
            'mute' => $this->setMode('muted', 'Everything muted. Leads still appear on your dashboard. Reply RESUME to turn alerts back on.'),
            'resume', 'unmute', 'start' => $this->setMode('normal', 'Alerts are back on.'),
            'leads', 'status', 'list' => $this->latestLeads(),
            'help', 'commands', '?' => $this->menu(),
            default => $this->menu(),
        };
    }

    /**
     * Act on the most recently alerted, still-open lead — the one the
     * operator is almost certainly replying about.
     */
    protected function markNewest(LeadStatus $status, string $verb): string
    {
        $lead = $this->newestOpenLead();

        if ($lead === null) {
            return 'No open lead to update right now.';
        }

        $lead->update(['status' => $status]);

        ActivityLog::record(ActivityType::LeadStatusUpdated, subject: $lead, meta: [
            'status' => $status->value,
            'source' => 'whatsapp_command',
        ]);

        // Marking it bid or skipped stops its reminders immediately, because
        // the reminder sweep only considers leads still in `ready`.
        return ucfirst($verb)." — \"{$lead->title}\" ({$lead->score}/10). No more reminders for it.";
    }

    protected function setMode(string $mode, string $confirmation): string
    {
        $this->settings->set('whatsapp_alert_mode', $mode);

        return $confirmation;
    }

    protected function latestLeads(): string
    {
        $leads = Lead::query()
            ->where('status', LeadStatus::Ready)
            ->orderByDesc('score')
            ->orderByDesc('posted_at')
            ->limit(3)
            ->get();

        if ($leads->isEmpty()) {
            return 'Nothing open right now.';
        }

        $lines = $leads->map(fn (Lead $l) => sprintf(
            '%d/10 · %s · %s · posted %s',
            (int) ($l->score ?? 0),
            (string) $l->title,
            (string) ($l->budget ?: 'budget n/a'),
            $l->posted_at?->diffForHumans() ?? 'unknown',
        ));

        return $leads->count()." open:\n".$lines->implode("\n");
    }

    protected function menu(): string
    {
        return "Commands: BID, SKIP, LEADS, PAUSE, MUTE, RESUME.";
    }

    protected function newestOpenLead(): ?Lead
    {
        return Lead::query()
            ->where('status', LeadStatus::Ready)
            ->orderByDesc('id')
            ->first();
    }
}
