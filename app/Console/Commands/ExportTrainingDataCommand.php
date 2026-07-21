<?php

namespace App\Console\Commands;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\ProposalVersion;
use App\Services\Ai\ScoringService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Exports (system + job brief) -> final proposal pairs as provider-neutral
 * chat JSONL - the format OpenAI, Azure, Together AI, and Fireworks AI all
 * accept unchanged. This builds the training dataset; the fine-tune itself is
 * a separate manual step on whichever provider will take the account (a brand
 * new OpenAI org currently cannot create fine-tune jobs).
 *
 * The "final proposal" for a lead is the text frozen when it was marked sent
 * (the is_sent version), falling back to the live proposal_text for leads sent
 * before versioning existed. This command is strictly READ-ONLY: it never
 * touches a lead, proposal, or version row.
 */
class ExportTrainingDataCommand extends Command
{
    protected $signature = 'proposals:export-training-data
        {--month= : Month to export as YYYY-MM (defaults to the current month)}
        {--replied-only : Only leads that drew a reply (status replied or won)}
        {--split=0.15 : Fraction held out as a validation set}';

    protected $description = 'Export sent proposals as brief->final JSONL training pairs (read-only)';

    /** Leads whose proposal is treated as "sent" and eligible for export. */
    protected const SENT_STATUSES = [LeadStatus::Sent, LeadStatus::Replied, LeadStatus::Won];

    /** With --replied-only, a proven-outcome subset of the above. */
    protected const REPLIED_STATUSES = [LeadStatus::Replied, LeadStatus::Won];

    public function handle(SettingsService $settings, ScoringService $scoring): int
    {
        $monthOpt = $this->option('month');

        try {
            $start = $monthOpt
                ? Carbon::createFromFormat('Y-m', $monthOpt)->startOfMonth()
                : now()->startOfMonth();
        } catch (\Throwable) {
            $this->error("Invalid --month '{$monthOpt}'. Use YYYY-MM, e.g. 2026-07.");

            return self::INVALID;
        }

        $end = (clone $start)->endOfMonth();
        $split = max(0.0, min(0.9, (float) $this->option('split')));

        $statuses = $this->option('replied-only') ? self::REPLIED_STATUSES : self::SENT_STATUSES;

        // The system message is the voice the model should learn. Prefer an
        // explicit training prompt; fall back to the live drafting skill so the
        // exported pairs teach the same rules the writer follows today.
        $system = trim((string) $settings->get('training_system_prompt'))
            ?: trim((string) $settings->get('proposal_skill'));

        $lines = [];

        Lead::query()
            ->whereNotNull('proposal_text')
            ->whereIn('status', array_map(fn (LeadStatus $s) => $s->value, $statuses))
            ->with(['proposalVersions' => fn ($q) => $q->where('is_sent', true)])
            ->chunkById(200, function ($leads) use (&$lines, $scoring, $system, $start, $end) {
                foreach ($leads as $lead) {
                    /** @var ProposalVersion|null $sent */
                    $sent = $lead->proposalVersions->first();

                    // The moment the proposal went out - the sent version's
                    // timestamp, else the lead's last change as a proxy for
                    // proposals sent before versioning.
                    $sentAt = $sent?->sent_at ?? $lead->updated_at;
                    if ($sentAt === null || $sentAt->lt($start) || $sentAt->gt($end)) {
                        continue;
                    }

                    $final = $sent?->body ?? $lead->proposal_text;
                    if (trim((string) $final) === '') {
                        continue;
                    }

                    $lines[] = json_encode([
                        'messages' => [
                            ['role' => 'system', 'content' => $system],
                            ['role' => 'user', 'content' => $scoring->jobContent($lead, 'JOB POST (write a proposal for this):')],
                            ['role' => 'assistant', 'content' => $final],
                        ],
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            });

        if ($lines === []) {
            $this->warn("No sent proposals found for {$start->format('Y-m')}".($this->option('replied-only') ? ' (replied-only)' : '').'.');

            return self::SUCCESS;
        }

        shuffle($lines);

        $validationCount = (int) round(count($lines) * $split);
        // Never hold back the entire tiny set - training needs at least one row.
        $validationCount = min($validationCount, count($lines) - 1);
        $validation = array_splice($lines, 0, $validationCount);
        $train = $lines;

        $dir = storage_path('app/training');
        File::ensureDirectoryExists($dir);

        $month = $start->format('Y-m');
        $trainPath = "{$dir}/train-{$month}.jsonl";
        $validationPath = "{$dir}/validation-{$month}.jsonl";

        File::put($trainPath, $train === [] ? '' : implode("\n", $train)."\n");
        File::put($validationPath, $validation === [] ? '' : implode("\n", $validation)."\n");

        $this->info("Exported ".count($train)." training + ".count($validation)." validation example(s) for {$month}.");
        $this->line("  train:      {$trainPath}");
        $this->line("  validation: {$validationPath}");

        return self::SUCCESS;
    }
}
