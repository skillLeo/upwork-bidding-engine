<?php

namespace App\Console\Commands;

use App\Console\Concerns\RunsForTenants;
use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\AppNotification;
use App\Models\Lead;
use App\Services\NotificationService;
use App\Services\OpenClawService;
use App\Services\SettingsService;
use App\Services\WhatsApp\WhatsAppCloudService;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * A fresh, high-scoring lead's value decays fast — this is the safety net
 * for the golden window between the first alert landing and the lead going
 * stale. Exactly two reminders per lead, ever: 45 minutes and 90 minutes
 * after that first alert, and only while the lead is still `ready` (still
 * unbid and unskipped — any dashboard status change stops it immediately,
 * same as marking an email read stops its reminder). Never more, never a
 * recurring nag — a reminder that fires every hour trains the operator to
 * ignore it, which defeats the point.
 *
 * CHANNEL ORDER: Web Push (plus the in-app bell) is the PRIMARY channel and
 * always fires first. WhatsApp is a secondary convenience copy attempted
 * afterwards, in its own try/catch, and can never block or undo the primary
 * send. This inversion is deliberate: previously the whole command returned
 * early when OpenClaw was unconfigured, reminder eligibility depended on a
 * successful WhatsApp send having been logged, and the Web Push mirror sat
 * AFTER the WhatsApp call inside one try — so when the OpenClaw tunnel went
 * down (which it does), the free, working Web Push reminder silently died
 * with it. Nothing here may depend on WhatsApp being reachable.
 */
class SendLeadRemindersCommand extends Command
{
    use RunsForTenants;

    public const FIRST_REMINDER_MINUTES = 45;

    public const SECOND_REMINDER_MINUTES = 90;

    /**
     * Fallback only. The live bar is the notify_score_min setting, shared
     * with NotificationDispatcher so a lead can never be alert-worthy but
     * not reminder-worthy (or the reverse).
     */
    public const MIN_SCORE = 8;

    public const MAX_LEAD_AGE_HOURS = 6;

    protected $signature = 'leads:send-reminders
        {--tenant= : run for this tenant only (default: every operable tenant)}';

    protected $description = 'Send up to two reminders (Web Push first, WhatsApp if available) for fresh, high-scoring leads still sitting unbid.';

    public function handle(OpenClawService $openClaw, SettingsService $settings): int
    {
        return $this->forEachTenant(fn () => $this->runForTenant($openClaw, $settings));
    }

    protected function runForTenant(OpenClawService $openClaw, SettingsService $settings): int
    {
        // NOTE: deliberately NO early return on WhatsApp/OpenClaw being
        // unconfigured. Reminders are a Web Push feature that WhatsApp may
        // additionally mirror — not the other way round.

        // 'paused' and 'muted' both stop reminders on every channel — this is
        // the operator's global quiet switch, not a WhatsApp-only one. They
        // differ only on whether brand new lead cards still go out (that
        // check lives in NotifyBidderJob, not here).
        if ($settings->whatsappAlertMode() !== 'normal') {
            return self::SUCCESS;
        }

        // Pakistan-time quiet hours, independent of the app's storage
        // timezone (UTC) — a reminder buzzing the operator's phone at 2am
        // is worse than no reminder at all. Operator-configurable; the
        // default 23->7 wraps midnight.
        if ($this->inQuietHours($settings)) {
            return self::SUCCESS;
        }

        $leads = Lead::query()
            ->where('status', LeadStatus::Ready)
            ->where('score', '>=', (int) $settings->get('notify_score_min', self::MIN_SCORE))
            ->where('posted_at', '>=', now()->subHours(self::MAX_LEAD_AGE_HOURS))
            ->get();

        $dashboardUrl = rtrim((string) config('skillleo.frontend_url'), '/');

        // WhatsApp is optional extra reach; its absence must not stop anything.
        // The OpenClaw bridge is internal-only (P8) — it drives one physical,
        // company-owned WhatsApp session, so a customer workspace must never
        // reach it. Same resolution as NotificationDispatcher::whatsappAvailable().
        $tenant = Tenancy::current();
        $whatsappAvailable = $tenant !== null
            && $tenant->isInternal()
            && (bool) $settings->bidderWhatsapp()
            && (bool) $settings->openClawUrl();

        foreach ($leads as $lead) {
            $this->maybeSendReminder($lead, $openClaw, $dashboardUrl, $whatsappAvailable);
        }

        return self::SUCCESS;
    }

    protected function maybeSendReminder(Lead $lead, OpenClawService $openClaw, string $dashboardUrl, bool $whatsappAvailable): void
    {
        $notifiedAt = $this->firstAlertedAt($lead);

        // We never alerted on this lead at all yet — nothing to remind about.
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

        $minutesSinceNotified = (int) $notifiedAt->diffInMinutes(now());
        $dueReminderNumber = $remindersSent + 1;
        $dueAtMinutes = $dueReminderNumber === 1 ? self::FIRST_REMINDER_MINUTES : self::SECOND_REMINDER_MINUTES;

        if ($minutesSinceNotified < $dueAtMinutes) {
            return;
        }

        $message = "Still unbid: {$lead->score}/10 \"{$lead->title}\" — reminder {$dueReminderNumber} of 2.";

        // PRIMARY: Web Push + in-app bell (+ email, per each member's own
        // preferences). Free, needs no Mac, no tunnel, and no Meta.
        $delivered = false;

        try {
            app(NotificationService::class)->reminder($lead, $message);
            $delivered = true;
        } catch (\Throwable $e) {
            report($e);
        }

        // The 45/90 cadence advances on the PRIMARY channel, so a dead
        // WhatsApp tunnel can neither hold the schedule back nor cause the
        // same reminder to be re-sent forever.
        if ($delivered) {
            ActivityLog::record('lead_reminder_sent', subject: $lead, meta: [
                'reminder_number' => $dueReminderNumber,
                'minutes_since_notified' => $minutesSinceNotified,
                'channels' => $whatsappAvailable ? ['push', 'whatsapp'] : ['push'],
            ]);
        }

        // SECONDARY: a convenience copy on WhatsApp. Isolated in its own
        // try/catch AFTER the primary send, so an offline tunnel is logged
        // and forgotten rather than taking the reminder down with it.
        $cloud = app(WhatsAppCloudService::class);

        if ($cloud->isConfigured()) {
            try {
                $cloud->sendAlert(
                    $message.' '.$dashboardUrl.'/leads/'.$lead->id,
                    [
                        ((int) ($lead->score ?? 0)).'/10',
                        (string) $lead->title,
                        (string) ($lead->budget ?: 'budget not stated'),
                        'reminder '.$dueReminderNumber.' of 2',
                    ],
                );
            } catch (\Throwable $e) {
                report($e);
            }

            return;
        }

        if (! $whatsappAvailable) {
            return;
        }

        try {
            $openClaw->sendBidReminder($lead, "{$dashboardUrl}/leads/{$lead->id}", $dueReminderNumber);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Is it currently inside the operator's quiet window, in Pakistan time?
     * start > end wraps midnight (23 -> 7, the default). start == end means
     * the window is disabled rather than 24 hours long — a config that
     * silenced every reminder forever would be a foot-gun.
     */
    protected function inQuietHours(SettingsService $settings): bool
    {
        $start = (int) $settings->get('quiet_hours_start', 23);
        $end = (int) $settings->get('quiet_hours_end', 7);

        if ($start === $end) {
            return false;
        }

        $hour = now()->setTimezone('Asia/Karachi')->hour;

        return $start > $end
            ? ($hour >= $start || $hour < $end)   // wraps midnight
            : ($hour >= $start && $hour < $end);  // same-day window
    }

    /**
     * When this lead first rang the operator's phone, independent of channel.
     *
     * Anchored on the in-app/Web Push notification row, which ScoreLeadJob
     * creates for every ready lead BEFORE it attempts WhatsApp — so the
     * reminder clock starts even when WhatsApp is down. Falls back to the
     * old BidderNotified activity log for leads alerted before this existed.
     */
    protected function firstAlertedAt(Lead $lead): ?Carbon
    {
        $notifiedAt = AppNotification::query()
            ->where('type', 'lead')
            ->where('lead_id', $lead->id)
            ->value('created_at');

        $notifiedAt ??= ActivityLog::query()
            ->where('type', ActivityType::BidderNotified->value)
            ->where('subject_type', $lead->getMorphClass())
            ->where('subject_id', $lead->id)
            ->value('created_at');

        return $notifiedAt === null ? null : Carbon::parse($notifiedAt);
    }
}
