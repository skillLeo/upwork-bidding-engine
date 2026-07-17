<?php

namespace App\Jobs;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Services\OpenClawService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyBidderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $leadId) {}

    /**
     * One retry, 30 seconds after the first failure.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30];
    }

    public function handle(OpenClawService $openClaw): void
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

        $dashboardUrl = rtrim((string) config('skillleo.frontend_url'), '/')."/leads/{$lead->id}";

        try {
            $openClaw->sendLeadCard($lead, $dashboardUrl);

            ActivityLog::record(ActivityType::BidderNotified, subject: $lead, meta: [
                'channel' => 'whatsapp',
            ]);
        } catch (\Throwable $e) {
            report($e);

            ActivityLog::record(ActivityType::BidderNotifyFailed, subject: $lead, meta: [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $lead = Lead::find($this->leadId);

        if ($lead) {
            ActivityLog::record(ActivityType::BidderNotifyFailed, subject: $lead, meta: [
                'error' => $exception->getMessage(),
                'final' => true,
            ]);
        }
    }
}
