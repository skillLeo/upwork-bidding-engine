<?php

namespace App\Console\Commands;

use App\Console\Concerns\RunsForTenants;
use App\Models\Lead;
use App\Services\Ai\ProposalService;
use Illuminate\Console\Command;

/**
 * Prints the EXACT prompt a proposal draft call would send for a lead —
 * no API call, no cost. Exists so the operator can verify, after any
 * settings edit, that the model sees only SKILL.md v2 + the fact sheet +
 * the format spec (and never the teaching document).
 */
class AiShowPromptCommand extends Command
{
    use RunsForTenants;

    protected $signature = 'ai:show-prompt {lead_id}
        {--tenant= : run for this tenant only}';

    protected $description = 'Print the exact system + user blocks a proposal call would send for a lead';

    public function handle(ProposalService $proposals): int
    {
        return $this->forOneTenant(fn () => $this->runForTenant($proposals));
    }

    protected function runForTenant(ProposalService $proposals): int
    {
        $lead = Lead::find($this->argument('lead_id'));

        if (! $lead) {
            $this->error('Lead not found.');

            return self::FAILURE;
        }

        $system = $proposals->systemPrompt();
        $user = $proposals->draftUserPrompt($lead, [
            'score' => (int) ($lead->score ?? 7),
            'boost' => (bool) $lead->boost,
            'reason' => (string) $lead->score_reason,
        ]);

        $this->line('════ SYSTEM BLOCK (static, cache_control: ephemeral) — '.number_format(mb_strlen($system)).' chars ════');
        $this->line($system);
        $this->newLine();
        $this->line('════ USER BLOCK (variable, always last) — '.number_format(mb_strlen($user)).' chars ════');
        $this->line($user);

        return self::SUCCESS;
    }
}
