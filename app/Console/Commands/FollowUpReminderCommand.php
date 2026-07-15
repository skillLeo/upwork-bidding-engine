<?php

namespace App\Console\Commands;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Services\OpenClawService;
use App\Services\SettingsService;
use Illuminate\Console\Command;

class FollowUpReminderCommand extends Command
{
    protected $signature = 'leads:follow-up-reminders';

    protected $description = 'Ping the bidder on WhatsApp for sent leads that have had no reply after the configured follow-up window.';

    public function handle(SettingsService $settings, OpenClawService $openClaw): int
    {
        $days = (int) $settings->get('followup_days', 3);
        $cutoff = now()->subDays($days);

        $leads = Lead::query()
            ->where('status', LeadStatus::Sent)
            ->where('updated_at', '<=', $cutoff)
            ->get();

        if ($leads->isEmpty()) {
            $this->info('No leads need a follow-up reminder.');

            return self::SUCCESS;
        }

        $dashboardBase = rtrim((string) config('skillleo.frontend_url'), '/');

        foreach ($leads as $lead) {
            try {
                $openClaw->sendFollowUp($lead, "{$dashboardBase}/leads/{$lead->id}");

                ActivityLog::record(ActivityType::FollowUpSent, subject: $lead, meta: [
                    'followup_days' => $days,
                ]);

                // Leads has no dedicated sent_at column, so updated_at doubles as the
                // follow-up clock — touching it here means "no reply yet" leads get
                // reminded again every `followup_days`, instead of every single day.
                $lead->touch();

                $this->line("Sent follow-up reminder for lead #{$lead->id}: {$lead->title}");
            } catch (\Throwable $e) {
                report($e);
                $this->error("Failed to send follow-up for lead #{$lead->id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
