<?php

namespace App\Console\Commands;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Services\OpenClawService;
use App\Services\SettingsService;
use Illuminate\Console\Command;

/**
 * A fresh, high-scoring lead's value decays fast — this is the safety net
 * for the golden window between the WhatsApp card landing and it going
 * stale. Exactly two reminders per lead, ever: 45 minutes and 90 minutes
 * after the original card, and only while the lead is still `ready` (still
 * unbid and unskipped — any dashboard status change stops it immediately,
 * same as marking an email read stops its reminder). Never more, never a
 * recurring nag — a reminder that fires every hour trains the operator to
 * ignore WhatsApp, which defeats the point.
 */
class SendLeadRemindersCommand extends Command
{
    public const FIRST_REMINDER_MINUTES = 45;

    public const SECOND_REMINDER_MINUTES = 90;

    public const MIN_SCORE = 8;

    public const MAX_LEAD_AGE_HOURS = 6;

    protected $signature = 'leads:send-reminders';

    protected $description = 'Send up to two WhatsApp reminders for fresh, high-scoring leads still sitting unbid.';

    public function handle(OpenClawService $openClaw, SettingsService $settings): int
    {
        if (! $settings->bidderWhatsapp() || ! $settings->openClawUrl()) {
            return self::SUCCESS;
        }

        // 'paused' and 'muted' both stop reminders — they only differ on
        // whether brand new lead cards still go out (that check lives in
        // NotifyBidderJob, not here).
        if ($settings->whatsappAlertMode() !== 'normal') {
            return self::SUCCESS;
        }

        // Pakistan-time quiet hours, independent of the app's storage
        // timezone (UTC) — a reminder buzzing the operator's phone at 2am
        // is worse than no reminder at all.
        $karachiHour = now()->setTimezone('Asia/Karachi')->hour;

        if ($karachiHour >= 23 || $karachiHour < 7) {
            return self::SUCCESS;
        }

        $leads = Lead::query()
            ->where('status', LeadStatus::Ready)
            ->where('score', '>=', self::MIN_SCORE)
            ->where('posted_at', '>=', now()->subHours(self::MAX_LEAD_AGE_HOURS))
            ->get();

        $dashboardUrl = rtrim((string) config('skillleo.frontend_url'), '/');

        foreach ($leads as $lead) {
            $this->maybeSendReminder($lead, $openClaw, $dashboardUrl);
        }

        return self::SUCCESS;
    }

    protected function maybeSendReminder(Lead $lead, OpenClawService $openClaw, string $dashboardUrl): void
    {
        $notifiedAt = ActivityLog::query()
            ->where('type', ActivityType::BidderNotified->value)
            ->where('subject_type', $lead->getMorphClass())
            ->where('subject_id', $lead->id)
            ->value('created_at');

        // No original card ever went out (still queued, or notification
        // failed) — nothing to remind about yet.
        if ($notifiedAt === null) {
            return;
        }

        $remindersSent = ActivityLog::query()
            ->where('type', 'lead_reminder_sent')
            ->where('subject_type', $lead->getMorphClass())
            ->where('subject_id', $lead->id)
            ->count();

        if ($remindersSent >= 2) {
            return;
        }

        $minutesSinceNotified = \Illuminate\Support\Carbon::parse($notifiedAt)->diffInMinutes(now());
        $dueReminderNumber = $remindersSent + 1;
        $dueAtMinutes = $dueReminderNumber === 1 ? self::FIRST_REMINDER_MINUTES : self::SECOND_REMINDER_MINUTES;

        if ($minutesSinceNotified < $dueAtMinutes) {
            return;
        }

        try {
            $openClaw->sendBidReminder($lead, "{$dashboardUrl}/leads/{$lead->id}", $dueReminderNumber);

            ActivityLog::record('lead_reminder_sent', subject: $lead, meta: [
                'reminder_number' => $dueReminderNumber,
                'minutes_since_notified' => $minutesSinceNotified,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
