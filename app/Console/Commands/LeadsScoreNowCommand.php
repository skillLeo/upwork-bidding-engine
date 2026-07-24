<?php

namespace App\Console\Commands;

use App\Enums\ActivityType;
use App\Enums\LeadOutcome;
use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Services\OpenClawService;
use App\Services\ScoringService;
use App\Services\SettingsService;
use Illuminate\Console\Command;

/**
 * Scores one lead synchronously and prints every step - the hard filter
 * result, the exact request sent to OpenClaw, and the raw response -
 * instead of waiting on a webhook delivery to test the pipeline.
 */
class LeadsScoreNowCommand extends Command
{
    protected $signature = 'leads:score-now {id : The lead ID to score}';

    protected $description = 'Score one lead right now, synchronously, printing the full request/response for debugging.';

    public function handle(ScoringService $scoring, OpenClawService $openClaw, SettingsService $settings): int
    {
        $lead = Lead::find($this->argument('id'));

        if (! $lead) {
            $this->error("Lead #{$this->argument('id')} not found.");

            return self::FAILURE;
        }

        $this->info("Lead #{$lead->id}: {$lead->title}");
        $this->line("Current status: {$lead->status->value}, score: ".($lead->score ?? 'null'));
        $this->newLine();

        if (! $settings->aiEngineEnabled()) {
            $this->warn('AI engine is OFF (Settings → AI Engine → "AI engine online" toggle). Scoring would be skipped entirely for a real lead right now.');
        }

        $filter = $scoring->applyHardFilters($lead);
        $this->line('Hard filter: '.($filter['passed'] ? '<fg=green>passed</>' : '<fg=red>failed</> — '.$filter['reason']));

        if (! $filter['passed']) {
            $this->warn('A real lead would stop here (archived, no AI call made). Not writing this test result to the lead.');

            return self::SUCCESS;
        }

        $rules = $settings->rules();
        $requestPayload = [
            'skill' => 'score_and_write',
            'job' => [
                'title' => $lead->title,
                'brief' => $lead->full_brief,
                'budget' => $lead->budget,
                'proposals' => $lead->proposal_count,
                'client_spend' => $lead->client_spend,
                'client_hire_rate' => $lead->client_hire_rate,
                'payment_verified' => $lead->payment_verified,
            ],
            'rules' => [
                'stack' => $rules['stack_keywords'] ?? [],
                'min_budget' => $rules['min_budget'] ?? 0,
            ],
        ];

        $this->newLine();
        $this->line('<fg=cyan>Request to OpenClaw (/task):</>');
        $this->line(json_encode($requestPayload, JSON_PRETTY_PRINT));

        $reachable = $openClaw->isReachable();
        $this->newLine();
        $this->line('OpenClaw reachable: '.($reachable ? '<fg=green>yes</>' : '<fg=red>no</>'));

        if (! $reachable) {
            $this->error('OpenClaw did not answer its health check. A real lead would queue for retry here, not fail permanently.');

            return self::FAILURE;
        }

        $started = microtime(true);

        try {
            $result = $openClaw->scoreAndWrite($lead, $rules);
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('Scoring call failed: '.get_class($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $started, 1);

        $this->newLine();
        $this->line("<fg=cyan>Response ({$elapsed}s):</>");
        $this->line(json_encode($result, JSON_PRETTY_PRINT));

        $cutoff = (int) $settings->get('score_cutoff', 7);
        $isReady = $result['score'] >= $cutoff;

        $lead->update([
            'score' => $result['score'],
            'score_reason' => $result['reason'],
            'proposal_text' => $result['proposal'],
            'status' => $isReady ? LeadStatus::Ready : LeadStatus::Archived,
            'outcome' => $isReady ? null : LeadOutcome::AutoFiltered,
            'outcome_at' => $isReady ? null : now(),
        ]);

        ActivityLog::record(ActivityType::LeadScored, subject: $lead, meta: [
            'score' => $result['score'],
            'ready' => $isReady,
            'via' => 'leads:score-now',
        ]);

        $this->newLine();
        $this->info("Saved. Score {$result['score']}/10 (cutoff {$cutoff}) — status: ".($isReady ? 'Ready' : 'Archived'));

        if ($isReady) {
            $this->comment('This would normally also trigger a WhatsApp notification (skipped here — run separately if you want to test that too).');
        }

        return self::SUCCESS;
    }
}
