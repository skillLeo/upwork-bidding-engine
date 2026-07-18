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
 * The writing rules live in Settings — operator-owned, browser-editable,
 * never in code. Deliberately, the model receives ONLY proposal_skill
 * (SKILL.md v2) + project_facts + the format spec below: the teaching
 * document (proposal_reference) is written in the exact style the rules
 * ban, and models imitate their context more than they obey it, so it
 * must never enter a prompt. The gate's own lists/bounds are settings
 * too (SettingsService::proposalGate).
 */
class ProposalService
{
    // Generous because Sonnet 5 runs adaptive thinking by default and
    // max_tokens bounds thinking + text together.
    public const MAX_TOKENS = 8000;

    /**
     * The output contract — plumbing, not bidding rules, so it lives in
     * code where it stays byte-identical between calls (prompt caching
     * requires the static block to never vary).
     */
    public const FORMAT_SPEC = <<<'SPEC'
        ## OUTPUT FORMAT (strict)
        - If the job post contains screening questions: answer them FIRST, each answer as plain text. Number the answers ONLY if the client numbered their questions. Then the cover letter.
        - If there are no screening questions: output only the cover letter.
        - Plain text only. No "---" separators, no markdown, no headers, no bold, no bullets, no code fences.
        - End with the first-name signature line, nothing after it.
        SPEC;

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

        $system = $this->systemPrompt();
        $jobBlock = $this->jobBlock($lead, $scoring);

        $model = (string) $this->settings->get('proposal_model', 'claude-sonnet-5');
        $gate = $this->settings->proposalGate();

        $response = $this->ai->complete(
            'proposal',
            $system,
            $this->draftUserPrompt($lead, $scoring),
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
     * The variable half of the draft call — job brief + client stats +
     * score context, always LAST (after the cached static block). Public
     * for the same verification reason as systemPrompt().
     *
     * @param  array{score: int, boost: bool, reason: string}  $scoring
     */
    public function draftUserPrompt(Lead $lead, array $scoring): string
    {
        return $this->jobBlock($lead, $scoring)
            ."\nWrite the proposal now. Follow EVERY rule in your instructions, run every self-edit pass and the final self-check checklist before answering. Return ONLY the proposal text, nothing else.";
    }

    /**
     * @param  array{score: int, boost: bool, reason: string}  $scoring
     */
    protected function jobBlock(Lead $lead, array $scoring): string
    {
        return $this->scoring->jobContent($lead, 'JOB POST (write a proposal for this):')
            ."\n\nThis job scored {$scoring['score']}/10"
            .($scoring['reason'] !== '' ? " — {$scoring['reason']}" : '');
    }

    /**
     * The exact static block every proposal call sends: SKILL.md v2, then
     * the fact sheet, then the format spec — in that order, byte-identical
     * between calls so Anthropic's cache_control on it actually hits.
     * Public so the operator can print/verify precisely what the model
     * sees (and confirm the teaching document is NOT in it).
     */
    public function systemPrompt(): string
    {
        $skill = trim((string) $this->settings->get('proposal_skill'));

        if ($skill === '') {
            throw new \RuntimeException(
                'proposal_skill is empty — paste SKILL.md v2 into Settings → AI models & prompts before generating proposals.'
            );
        }

        return $skill
            ."\n\n## PROJECT FACTS (the ONLY source of truth about Hassam's track record — never claim anything not derivable from this sheet)\n"
            .trim((string) $this->settings->get('project_facts'))
            ."\n\n".self::FORMAT_SPEC;
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
