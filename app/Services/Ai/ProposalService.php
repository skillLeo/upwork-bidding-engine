<?php

namespace App\Services\Ai;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Services\SettingsService;

/**
 * Writes the proposal — SEQUENTIAL by design: callers must only invoke
 * this after ScoringService returned score >= score_cutoff. Below-cutoff
 * leads are archived with the scoring reason and never reach this class;
 * that ordering (not this file) is what saves ~40% of AI spend.
 *
 * A single generation call can't police a 70KB rulebook, so every
 * proposal runs through the quality gate before it's returned:
 *
 *   draft → ProposalLinter (mechanical: banned phrases, word count,
 *   signature, "Done =", contact info) → model review pass (the model
 *   re-reads its own draft against EVERY settings rule and lists
 *   violations as JSON) → revision with the violations spelled out →
 *   re-checked, up to MAX_REVISIONS times.
 *
 * The lint runs first because it's free; the paid review pass only runs
 * on a lint-clean draft. If a draft still violates after the last
 * revision it is returned anyway (a slightly-off proposal the operator
 * can see beats a dead pipeline) with a proposal_quality_warning logged.
 *
 * Like scoring, the actual writing rules live in proposal_system_prompt
 * in Settings — operator-owned, browser-editable, never in code. The
 * gate's own lists/bounds are settings too (SettingsService::proposalGate).
 */
class ProposalService
{
    // Generous because Sonnet 5 runs adaptive thinking by default and
    // max_tokens bounds thinking + text together.
    public const MAX_TOKENS = 8000;

    public const REVIEW_MAX_TOKENS = 2000;

    public const MAX_REVISIONS = 2;

    public function __construct(
        protected SettingsService $settings,
        protected AiManager $ai,
        protected ScoringService $scoring,
        protected ProposalLinter $linter,
    ) {}

    /**
     * @param  array{score: int, boost: bool, reason: string}  $scoring
     * @return array{text: string, response: AiResponse, clean: bool, revisions: int}
     */
    public function write(Lead $lead, array $scoring): array
    {
        // The full pipeline (draft + review + possible revisions) can run
        // 60s+ — never let a web-tier time limit kill it mid-generation.
        @set_time_limit(240);

        $system = trim((string) $this->settings->get('proposal_system_prompt'));

        if ($system === '') {
            throw new \RuntimeException(
                'proposal_system_prompt is empty — paste your proposal rules into Settings → AI models & prompts before generating proposals.'
            );
        }

        $jobBlock = $this->scoring->jobContent($lead)
            ."\n\nThis job scored {$scoring['score']}/10"
            .($scoring['reason'] !== '' ? " — {$scoring['reason']}" : '');

        $model = (string) $this->settings->get('proposal_model', 'claude-sonnet-5');
        $gate = $this->settings->proposalGate();

        $response = $this->ai->complete(
            'proposal',
            $system,
            $jobBlock."\nWrite the proposal now. Follow EVERY rule in your instructions, run every self-edit pass and the final self-check checklist before answering. Return ONLY the proposal text, nothing else.",
            $model,
            self::MAX_TOKENS,
            $lead->id,
        );

        $text = $this->cleanText($response->text);

        if (! $gate['enabled']) {
            return ['text' => $text, 'response' => $response, 'clean' => true, 'revisions' => 0];
        }

        $revisions = 0;
        $violations = [];

        while (true) {
            $violations = $this->linter->check($text);

            // Paid review only once the free lint is clean — no point
            // asking the model to judge a draft a regex already rejected.
            if ($violations === []) {
                $violations = $this->reviewWithModel($system, $jobBlock, $text, $model, $lead);
            }

            if ($violations === [] || $revisions >= self::MAX_REVISIONS) {
                break;
            }

            $revisions++;
            $response = $this->ai->complete(
                'proposal_revision',
                $system,
                $jobBlock
                    ."\n\nYOUR PREVIOUS DRAFT (rejected):\n---\n{$text}\n---\n\nIt breaks these rules:\n"
                    .implode("\n", array_map(fn (string $v) => "- {$v}", $violations))
                    ."\n\nRewrite the proposal fixing every violation above while still following ALL your other rules and the final self-check checklist. Return ONLY the corrected proposal text, nothing else.",
                $model,
                self::MAX_TOKENS,
                $lead->id,
            );

            $text = $this->cleanText($response->text);
        }

        if ($violations !== []) {
            // Best-effort result: visible to the operator, never silently
            // dropped — but flagged so a systemic drift shows up in logs.
            ActivityLog::record('proposal_quality_warning', subject: $lead, meta: [
                'revisions' => $revisions,
                'violations' => array_slice($violations, 0, 10),
            ]);
        }

        return [
            'text' => $text,
            'response' => $response,
            'clean' => $violations === [],
            'revisions' => $revisions,
        ];
    }

    /**
     * The model re-reads its own draft as a reviewer against the same
     * settings rulebook (identical system prompt = prompt-cache hit, so
     * this pass costs ~a tenth of the draft). Returns [] on pass. A
     * malformed review never blocks the pipeline — the linter stays the
     * hard gate; this pass is judgment, best-effort by design.
     *
     * @return array<int, string>
     */
    protected function reviewWithModel(string $system, string $jobBlock, string $draft, string $model, Lead $lead): array
    {
        try {
            $response = $this->ai->complete(
                'proposal_review',
                $system,
                $jobBlock
                    ."\n\nDRAFT PROPOSAL TO REVIEW:\n---\n{$draft}\n---\n\n"
                    .'You are now the REVIEWER, not the writer. Check the draft above against EVERY rule in your instructions: hard rules, structure (envelope line, one real-project proof, mini-plan with "Done =", one closing question), banned vocabulary, banned openers, word count, voice, and the final self-check checklist. '
                    .'Output JSON only: {"pass": true/false, "violations": ["specific rule broken and where", ...]}. If the draft follows every rule, output {"pass": true, "violations": []}. No other text.',
                $model,
                self::REVIEW_MAX_TOKENS,
                $lead->id,
            );
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $decoded = json_decode($this->cleanText($response->text), true);

        if (! is_array($decoded) || ! array_key_exists('pass', $decoded)) {
            ActivityLog::record('proposal_review_unparseable', subject: $lead, meta: [
                'raw' => mb_substr($response->text, 0, 500),
            ]);

            return [];
        }

        if ($decoded['pass'] === true) {
            return [];
        }

        $violations = array_values(array_filter(
            array_map(fn ($v) => trim((string) $v), (array) ($decoded['violations'] ?? [])),
            fn (string $v) => $v !== '',
        ));

        // pass=false with nothing actionable is treated as a pass — a
        // revision with no instructions would just be a reroll.
        return array_slice($violations, 0, 10);
    }

    protected function cleanText(string $raw): string
    {
        $text = trim(preg_replace('/^```\w*\s*|```\s*$/', '', trim($raw)) ?? '');

        if ($text === '') {
            throw new \RuntimeException('Proposal generation returned empty text.');
        }

        return $text;
    }
}
