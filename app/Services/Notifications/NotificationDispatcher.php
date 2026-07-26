<?php

namespace App\Services\Notifications;

use App\Jobs\NotifyBidderJob;
use App\Models\ActivityLog;
use App\Models\AppNotification;
use App\Models\Lead;
use App\Services\NotificationService;
use App\Services\SettingsService;

/**
 * The single place a lead alert is decided and fanned out.
 *
 * Every rule that answers "should this ring the operator's phone, and on
 * which channels" lives here and nowhere else — the score bar, the freshness
 * gate, the paused/muted switch, per-channel dedupe, and the one message
 * format shared by every channel. Notification logic was previously spread
 * across ScoreLeadJob, NotifyBidderJob, NotificationService and the reminder
 * command, which is precisely how a channel ends up silently skipped (see
 * the WhatsApp-gated Web Push reminder bug) or double-fired.
 *
 * CHANNEL PRIORITY, in order and by design:
 *   1. In-app bell   — always, even for a stale lead. Nothing is ever hidden
 *                      from the dashboard.
 *   2. Web Push      — primary real-time alert. Free, no Mac, no Meta, can't
 *                      be banned.
 *   3. WhatsApp      — secondary convenience copy, dispatched as a job so it
 *                      keeps its own retry/backoff, and never able to block
 *                      or undo 1 and 2.
 */
class NotificationDispatcher
{
    public function __construct(
        protected SettingsService $settings,
        protected NotificationService $notifications,
    ) {}

    /**
     * A freshly scored lead. Safe to call more than once for the same lead:
     * every channel is deduped, so a rescore never re-alerts.
     *
     * @return array{alerted: bool, reason: ?string, channels: array<int, string>}
     */
    public function leadReady(Lead $lead): array
    {
        $score = (int) ($lead->score ?? 0);
        $minScore = (int) $this->settings->get('notify_score_min', 8);

        if ($score < $minScore) {
            return $this->outcome(false, "score {$score} is below the alert threshold of {$minScore}");
        }

        // Dedupe: the bell row IS the record that this lead was alerted on,
        // so its presence means every channel already ran once.
        if ($this->alreadyAlerted($lead)) {
            return $this->outcome(false, 'already alerted');
        }

        // The bell always gets the lead — a suppressed alert must never mean
        // an invisible lead.
        $stale = $this->staleReason($lead);
        $muted = $this->settings->whatsappAlertMode() === 'muted';
        $ringPhone = $stale === null && ! $muted;

        $this->notifications->push('lead', $this->headline($lead), [
            'level' => $score >= 9 ? 'success' : 'info',
            'body' => $this->summary($lead),
            'url' => "/leads/{$lead->id}",
            'lead_id' => $lead->id,
            // Web Push and email ride this flag; the bell row is written
            // either way.
            'push' => $ringPhone,
        ]);

        if (! $ringPhone) {
            $reason = $stale ?? 'alerts are muted in Settings';

            $lead->update(['notification_skipped_reason' => $reason]);

            ActivityLog::record('notification_skipped', subject: $lead, meta: ['reason' => $reason]);

            return $this->outcome(false, $reason, ['bell']);
        }

        // WhatsApp last, as its own job so a dead tunnel retries in the
        // background instead of holding up anything above.
        $channels = ['bell', 'push'];

        if ($this->whatsappAvailable()) {
            $channels[] = 'whatsapp';

            try {
                NotifyBidderJob::dispatchSync($lead->id);
            } catch (\Throwable $e) {
                report($e);

                // Hand it to the queue so it retries with backoff. Guarded in
                // its own right: on a sync queue connection this runs inline
                // and would throw straight back out, taking down an alert
                // that has already succeeded on bell and push.
                try {
                    NotifyBidderJob::dispatch($lead->id)->delay(30);
                } catch (\Throwable $queued) {
                    report($queued);
                }
            }
        }

        return $this->outcome(true, null, $channels);
    }

    /**
     * The one wording every channel uses, so an alert is recognisable at a
     * glance wherever it lands: "Fresh 9/10 · Laravel API · $1,200 · posted 4m ago".
     */
    public function headline(Lead $lead): string
    {
        return 'Fresh '.((int) ($lead->score ?? 0)).'/10 lead';
    }

    public function summary(Lead $lead): string
    {
        $parts = [(string) $lead->title];

        if (filled($lead->budget)) {
            $parts[] = (string) $lead->budget;
        }

        if ($lead->posted_at !== null) {
            $parts[] = 'posted '.$lead->posted_at->diffForHumans();
        }

        return implode(' · ', $parts);
    }

    /**
     * Has this lead already rung the phone? Anchored on the bell row, which
     * is written for every alerted lead regardless of channel outcome.
     */
    public function alreadyAlerted(Lead $lead): bool
    {
        return AppNotification::query()
            ->where('type', 'lead')
            ->where('lead_id', $lead->id)
            ->exists();
    }

    /**
     * Freshness gate. A stale lead is still scored, still written, still on
     * the dashboard at its real score — it just doesn't interrupt anyone,
     * because a 3-day-old 8/10 already has a formed shortlist and the whole
     * strategy is speed. 0 disables the gate.
     */
    protected function staleReason(Lead $lead): ?string
    {
        $hours = (int) $this->settings->get('notification_freshness_hours', 48);

        if ($hours <= 0 || $lead->posted_at === null) {
            return null;
        }

        if ($lead->posted_at->gte(now()->subHours($hours))) {
            return null;
        }

        return sprintf(
            'stale at scoring time — posted %s, notification cutoff %dh',
            $lead->posted_at->diffForHumans(),
            $hours,
        );
    }

    protected function whatsappAvailable(): bool
    {
        return (bool) $this->settings->bidderWhatsapp() && (bool) $this->settings->openClawUrl();
    }

    /**
     * @param  array<int, string>  $channels
     * @return array{alerted: bool, reason: ?string, channels: array<int, string>}
     */
    protected function outcome(bool $alerted, ?string $reason = null, array $channels = []): array
    {
        return ['alerted' => $alerted, 'reason' => $reason, 'channels' => $channels];
    }
}
