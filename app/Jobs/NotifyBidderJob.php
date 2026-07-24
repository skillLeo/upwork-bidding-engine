<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsForTenant;
use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Services\OpenClawService;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyBidderJob implements ShouldQueue
{
    use RunsForTenant;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Initial attempt + three retries (30s, 2min, 10min), then stop and
     * surface the failure on the lead — never retry forever.
     */
    public int $tries = 4;

    public function __construct(public int $leadId)
    {
        $this->captureTenant();
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(OpenClawService $openClaw, SettingsService $settings): void
    {
        $this->forTenant(fn () => $this->run($openClaw, $settings));
    }

    protected function run(OpenClawService $openClaw, SettingsService $settings): void
    {
        $lead = Lead::find($this->leadId);

        if (! $lead || $lead->status !== LeadStatus::Ready) {
            return;
        }

        // Never message twice about the same lead — the sync-first,
        // queued-retry dispatch pattern means this job can legitimately be
        // enqueued more than once for one lead.
        $alreadySent = ActivityLog::query()
            ->where('type', ActivityType::BidderNotified->value)
            ->where('subject_type', $lead->getMorphClass())
            ->where('subject_id', $lead->id)
            ->exists();

        if ($alreadySent) {
            return;
        }

        // Global operator kill switch — 'muted' stops every automated
        // WhatsApp send including brand new lead cards ('paused' only
        // stops reminders/follow-ups and leaves this check unaffected).
        if ($settings->whatsappAlertMode() === 'muted') {
            $lead->update(['notification_skipped_reason' => 'WhatsApp alerts are muted in Settings']);

            ActivityLog::record('notification_skipped', subject: $lead, meta: [
                'reason' => 'whatsapp_muted',
            ]);

            return;
        }

        // Freshness gate — separate from the rubric's 7-day auto-reject.
        // A stale lead is still scored, written, and visible on the
        // dashboard at its real score; it just never rings the phone,
        // because a 3-day-old 8/10 already has a formed shortlist and the
        // whole strategy is speed. Sitting here (the single choke point)
        // the gate also covers backlog jobs queued before it existed.
        $freshHours = (int) $settings->get('notification_freshness_hours', 48);

        if ($freshHours > 0 && $lead->posted_at && $lead->posted_at->lt(now()->subHours($freshHours))) {
            $reason = sprintf(
                'stale at scoring time — posted %s, notification cutoff %dh',
                $lead->posted_at->diffForHumans(),
                $freshHours,
            );

            $lead->update(['notification_skipped_reason' => $reason]);

            ActivityLog::record('notification_skipped', subject: $lead, meta: [
                'reason' => $reason,
                'posted_at' => $lead->posted_at->toIso8601String(),
                'cutoff_hours' => $freshHours,
            ]);

            return;
        }

        $dashboardUrl = rtrim((string) config('skillleo.frontend_url'), '/')."/leads/{$lead->id}";

        try {
            $openClaw->sendLeadCard($lead, $dashboardUrl);

            // A success wipes any earlier failure badge from a previous try.
            $lead->update(['notify_error' => null, 'notification_skipped_reason' => null]);

            ActivityLog::record(ActivityType::BidderNotified, subject: $lead, meta: [
                'channel' => 'whatsapp',
            ]);
        } catch (\Throwable $e) {
            report($e);

            ActivityLog::record(ActivityType::BidderNotifyFailed, subject: $lead, meta: [
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            throw $e;
        }
    }

    /**
     * All retries exhausted: the failure becomes a red badge on the lead
     * itself (LeadDetail) and a row in the Health page's failure list —
     * a dead tunnel must never be silent again.
     */
    public function failed(\Throwable $exception): void
    {
        $lead = Lead::find($this->leadId);

        if (! $lead) {
            return;
        }

        $lead->update(['notify_error' => mb_substr($exception->getMessage(), 0, 480)]);

        ActivityLog::record(ActivityType::BidderNotifyFailed, subject: $lead, meta: [
            'error' => $exception->getMessage(),
            'final' => true,
        ]);
    }
}
