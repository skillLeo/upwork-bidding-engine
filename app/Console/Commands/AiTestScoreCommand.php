<?php

namespace App\Console\Commands;

use App\Console\Concerns\RunsForTenants;
use App\Models\AiCall;
use App\Models\Lead;
use App\Services\Ai\AiResponse;
use App\Services\Ai\ProposalService;
use App\Services\Ai\ScoringService;
use App\Services\SettingsService;
use Illuminate\Console\Command;

/**
 * Phase-A proving ground for the direct-API AI layer: runs the real
 * scoring call (and, only if the score clears the cutoff, the real
 * proposal call) against a lead and prints the request summary, response,
 * exact token usage, and cost. Read-only — never modifies the lead, never
 * notifies anyone.
 */
class AiTestScoreCommand extends Command
{
    use RunsForTenants;

    protected $signature = 'ai:test-score {lead_id : The lead to score}
        {--tenant= : run for this tenant only}';

    protected $description = 'Score a lead through the direct AI layer and print tokens + cost (no side effects)';

    public function handle(SettingsService $settings, ScoringService $scoring, ProposalService $proposals): int
    {
        return $this->forOneTenant(fn () => $this->runForTenant($settings, $scoring, $proposals));
    }

    protected function runForTenant(SettingsService $settings, ScoringService $scoring, ProposalService $proposals): int
    {
        $lead = Lead::find((int) $this->argument('lead_id'));

        if (! $lead) {
            $this->error('Lead not found.');

            return self::FAILURE;
        }

        $systemPrompt = trim((string) $settings->get('scoring_system_prompt'));
        $cutoff = (int) $settings->get('score_cutoff', 7);

        $this->line('── Request ─────────────────────────────');
        $this->line('Lead:            #'.$lead->id.' '.$lead->title);
        $this->line('Provider:        '.$settings->platform('ai_provider', 'anthropic'));
        $this->line('Scoring model:   '.$settings->platform('scoring_model', 'claude-haiku-4-5'));
        $this->line('Proposal model:  '.$settings->platform('proposal_model', 'claude-sonnet-5'));
        $this->line('System prompt:   '.strlen($systemPrompt).' chars (sha1 '.substr(sha1($systemPrompt), 0, 8).') from Settings');
        $this->line('Score cutoff:    '.$cutoff);

        try {
            $result = $scoring->score($lead);
        } catch (\Throwable $e) {
            $this->error('Scoring failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('── Scoring response ────────────────────');
        $this->line("Score:  {$result['score']}/10   BID: ".($result['bid'] ? 'yes' : 'no').'   BOOST: '.($result['boost'] ? 'yes' : 'no'));
        $this->line('Reason: '.$result['reason']);
        $this->printUsage($result['response']);

        if ($result['score'] < $cutoff) {
            $this->newLine();
            $this->info("Score below cutoff ({$cutoff}) — NO proposal call made. That skipped call is the ~40% spend saving.");
        } else {
            try {
                $proposal = $proposals->write($lead, $result);
            } catch (\Throwable $e) {
                $this->error('Proposal failed: '.$e->getMessage());

                return self::FAILURE;
            }

            $this->newLine();
            $this->line('── Proposal (sequential — only because score >= cutoff) ──');
            $this->line($proposal['text']);
            $this->printUsage($proposal['response']);
        }

        $this->newLine();
        $this->line('── Cost ledger (ai_calls) ──────────────');
        $today = AiCall::whereDate('created_at', today())->sum('cost_usd');
        $month = AiCall::where('created_at', '>=', now()->startOfMonth())->sum('cost_usd');
        $this->line(sprintf('Spend today: $%.4f   ·   This month: $%.4f', $today, $month));

        return self::SUCCESS;
    }

    protected function printUsage(AiResponse $response): void
    {
        $this->line(sprintf(
            'Usage:  %s/%s · in %d · out %d · cache-read %d · cache-write %d · %s · %sms',
            $response->provider,
            $response->model,
            $response->inputTokens,
            $response->outputTokens,
            $response->cachedTokens,
            $response->cacheWriteTokens,
            $response->costUsd !== null ? sprintf('$%.6f', $response->costUsd) : 'cost unknown',
            $response->durationMs,
        ));
    }
}
