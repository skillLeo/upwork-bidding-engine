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
 * Every version produced in a run (draft, each revision) is kept with its
 * lint and review status attached, and what ships is decided by a strict
 * priority ladder — the exact text that passed its checks, never a
 * mutation of it:
 *
 *   a. newest version that passed lint AND review
 *   b. else newest lint-clean version (its review violations go on the
 *      lead's warning badge)
 *   c. else ONE surgical-fix call on the best available version (fix only
 *      the listed mechanical violations, change nothing else), re-linted
 *   d. only if all of that fails, ship the best available version with
 *      the badge listing every unresolved violation.
 *
 * A lint-dirty version never ships while a lint-clean one exists — the
 * bug this ladder replaces shipped a dirty revision 2 over a clean
 * revision 1.
 *
 * STYLE CONTRACT: all feedback shown to the model (review output, linter
 * messages, the templates below) is rendered by code and must contain no
 * em dashes, no en dashes, no banned vocabulary — models imitate their
 * context, and dirty feedback is how a revision reintroduced an em dash.
 *
 * The writing rules live in Settings (proposal_skill + project_facts);
 * the teaching document (proposal_reference) never enters a prompt.
 */
class ProposalService
{
    // Generous because Sonnet 5 runs adaptive thinking by default and
    // max_tokens bounds thinking + text together.
    public const MAX_TOKENS = 8000;

    public const REVIEW_MAX_TOKENS = 4000;

    public const MAX_REVISIONS = 2;

    /**
     * The output contract — plumbing, not bidding rules, so it lives in
     * code where it stays byte-identical between calls (prompt caching
     * requires the static block to never vary).
     */
    public const FORMAT_SPEC = <<<'SPEC'
        ## OUTPUT FORMAT (strict)
        - If the job post contains screening questions: answer them FIRST, each answer as plain text. Number the answers ONLY if the client numbered their questions. Then the cover letter.
        - If there are no screening questions: output only the cover letter.
        - Plain text only. No separator lines, no markdown, no headers, no bold, no bullets, no code fences.
        - End with the first-name signature line, nothing after it.
        SPEC;

    public function __construct(
        protected SettingsService $settings,
        protected AiManager $ai,
        protected ScoringService $scoring,
        protected ProposalLinter $linter,
    ) {}

    /**
     * @param  array{score: int, boost: bool, reason: string}  $scoring
     * @return array{text: string, response: AiResponse, clean: bool, revisions: int, warnings: array<int, string>, shipped_rule: string, versions: array<int, array<string, mixed>>}
     */
    public function write(Lead $lead, array $scoring): array
    {
        if (! $this->settings->get('proposal_writing_enabled', true)) {
            throw new ProposalWritingPausedException(
                'Proposal writing is paused in Settings → AI models & prompts. Scoring still runs; no proposal is generated until this is turned back on.'
            );
        }

        // The full pipeline (draft + reviews + revisions + surgical fix)
        // can run 60s+ — never let a web-tier limit kill it mid-run.
        @set_time_limit(240);

        $system = $this->systemPrompt();
        $jobBlock = $this->jobBlock($lead, $scoring);
        $writerModel = (string) $this->settings->get('proposal_model', 'claude-sonnet-5');
        $reviewModel = (string) $this->settings->get('review_model', 'claude-sonnet-5');
        $gate = $this->settings->proposalGate();

        $response = $this->ai->complete(
            'proposal',
            $system,
            $this->draftUserPrompt($lead, $scoring),
            $writerModel,
            self::MAX_TOKENS,
            $lead->id,
        );

        $text = $this->cleanText($response->text);

        if (! $gate['enabled']) {
            return [
                'text' => $text,
                'response' => $response,
                'clean' => true,
                'revisions' => 0,
                'warnings' => [],
                'shipped_rule' => 'a',
                'versions' => [['source' => 'draft', 'text' => $text, 'lint' => [], 'review' => null, 'shipped' => true]],
            ];
        }

        $versions = [$this->evaluate($text, 'draft', $system, $jobBlock, $reviewModel, $lead, $response)];
        $revisions = 0;

        while ($this->openViolations(end($versions)) !== [] && $revisions < self::MAX_REVISIONS) {
            $revisions++;
            $latest = end($versions);

            $response = $this->ai->complete(
                'proposal_revision',
                $system,
                $jobBlock
                    ."\n\nYOUR PREVIOUS DRAFT (rejected):\n<<<DRAFT\n{$latest['text']}\nDRAFT>>>\n\n"
                    .$this->renderFeedback($this->openViolations($latest))
                    ."\n\nRewrite the proposal. Fix every violation listed above while following all your other rules and the final self-check checklist. Return ONLY the corrected proposal text, nothing else.",
                $writerModel,
                self::MAX_TOKENS,
                $lead->id,
            );

            $versions[] = $this->evaluate($this->cleanText($response->text), "revision {$revisions}", $system, $jobBlock, $reviewModel, $lead, $response);
        }

        return $this->ship($versions, $revisions, $system, $writerModel, $lead);
    }

    /**
     * The ship-priority ladder. Whatever ships is the EXACT text that was
     * checked — no mutation after the last check.
     *
     * @param  array<int, array<string, mixed>>  $versions
     * @return array{text: string, response: AiResponse, clean: bool, revisions: int, warnings: array<int, string>, shipped_rule: string, versions: array<int, array<string, mixed>>}
     */
    protected function ship(array $versions, int $revisions, string $system, string $writerModel, Lead $lead): array
    {
        $newestWhere = function (callable $test) use (&$versions): ?int {
            for ($i = count($versions) - 1; $i >= 0; $i--) {
                if ($test($versions[$i])) {
                    return $i;
                }
            }

            return null;
        };

        // a. Newest version that passed lint AND review.
        if (($i = $newestWhere(fn ($v) => $v['lint'] === [] && $v['review'] === [])) !== null) {
            return $this->result($versions, $i, 'a', [], $revisions);
        }

        // b. Newest lint-clean version; its review violations become the
        // visible badge on the lead.
        if (($i = $newestWhere(fn ($v) => $v['lint'] === [])) !== null) {
            $warnings = $versions[$i]['review'] ?? [];
            $this->warn($lead, $revisions, $warnings);

            return $this->result($versions, $i, 'b', $warnings, $revisions);
        }

        // c. One surgical-fix call on the best available version: only the
        // listed mechanical violations, nothing else, then re-lint.
        $best = $this->bestIndex($versions);

        try {
            $response = $this->ai->complete(
                'proposal_surgical_fix',
                $system,
                "Below is a proposal draft and a list of mechanical rule violations.\n\nDRAFT:\n<<<DRAFT\n{$versions[$best]['text']}\nDRAFT>>>\n\n"
                    .$this->renderFeedback($versions[$best]['lint'])
                    ."\n\nOutput the exact same text with only these violations fixed. Change nothing else, not one other word. Return ONLY the corrected text.",
                $writerModel,
                self::MAX_TOKENS,
                $lead->id,
            );

            $fixed = $this->cleanText($response->text);
            $versions[] = [
                'source' => 'surgical fix',
                'text' => $fixed,
                'lint' => $this->linter->check($fixed),
                'review' => null,
                'response' => $response,
                'shipped' => false,
            ];

            if (end($versions)['lint'] === []) {
                return $this->result($versions, count($versions) - 1, 'c', [], $revisions);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // d. Everything failed: ship the best available version with every
        // unresolved violation on the badge.
        $best = $this->bestIndex($versions);
        $warnings = array_merge($versions[$best]['lint'], $versions[$best]['review'] ?? []);
        $this->warn($lead, $revisions, $warnings);

        return $this->result($versions, $best, 'd', $warnings, $revisions);
    }

    /**
     * Lint first (free); only a lint-clean version earns the paid review.
     *
     * @return array<string, mixed>
     */
    protected function evaluate(string $text, string $source, string $system, string $jobBlock, string $reviewModel, Lead $lead, AiResponse $response): array
    {
        $lint = $this->linter->check($text);

        return [
            'source' => $source,
            'text' => $text,
            'lint' => $lint,
            'review' => $lint === [] ? $this->reviewWithModel($system, $jobBlock, $text, $reviewModel, $lead) : null,
            'response' => $response,
            'shipped' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $version
     * @return array<int, string>
     */
    protected function openViolations(array $version): array
    {
        return $version['lint'] !== [] ? $version['lint'] : ($version['review'] ?? []);
    }

    /**
     * Fewest lint violations wins; newest breaks ties.
     *
     * @param  array<int, array<string, mixed>>  $versions
     */
    protected function bestIndex(array $versions): int
    {
        $best = 0;

        foreach ($versions as $i => $version) {
            if (count($version['lint']) <= count($versions[$best]['lint'])) {
                $best = $i;
            }
        }

        return $best;
    }

    /**
     * @param  array<int, array<string, mixed>>  $versions
     * @param  array<int, string>  $warnings
     * @return array{text: string, response: AiResponse, clean: bool, revisions: int, warnings: array<int, string>, shipped_rule: string, versions: array<int, array<string, mixed>>}
     */
    protected function result(array $versions, int $shipped, string $rule, array $warnings, int $revisions): array
    {
        $versions[$shipped]['shipped'] = true;

        return [
            'text' => $versions[$shipped]['text'],
            'response' => $versions[$shipped]['response'],
            'clean' => $rule === 'a',
            'revisions' => $revisions,
            'warnings' => $warnings,
            'shipped_rule' => $rule,
            'versions' => array_map(
                fn (array $v) => ['source' => $v['source'], 'text' => $v['text'], 'lint' => $v['lint'], 'review' => $v['review'], 'shipped' => $v['shipped']],
                $versions,
            ),
        ];
    }

    /**
     * @param  array<int, string>  $warnings
     */
    protected function warn(Lead $lead, int $revisions, array $warnings): void
    {
        if ($warnings === []) {
            return;
        }

        ActivityLog::record('proposal_quality_warning', subject: $lead, meta: [
            'revisions' => $revisions,
            'violations' => array_slice($warnings, 0, 10),
        ]);
    }

    /**
     * Rendered by code from a fixed, style-clean template — numbered
     * plain sentences, nothing else.
     *
     * @param  array<int, string>  $violations
     */
    protected function renderFeedback(array $violations): string
    {
        $lines = [];

        foreach (array_values($violations) as $i => $violation) {
            $lines[] = ($i + 1).'. '.$violation;
        }

        return "It breaks these rules:\n".implode("\n", $lines);
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
            .($scoring['reason'] !== '' ? " ({$scoring['reason']})" : '');
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
            ."\n\n## PROJECT FACTS (the ONLY source of truth about Hassam's track record. Never claim anything not derivable from this sheet.)\n"
            .trim((string) $this->settings->get('project_facts'))
            ."\n\n".self::FORMAT_SPEC;
    }

    /**
     * The model re-reads the draft as a reviewer against the same settings
     * rulebook (identical system prompt = prompt-cache hit). Returns
     * rendered violation strings, [] on pass. Violations come back as
     * structured JSON and are rendered by code from a clean template, with
     * dash characters scrubbed, so the writer never sees banned style as
     * an example. A malformed review never blocks the pipeline — the
     * linter stays the hard gate; this pass is judgment, best-effort.
     *
     * @return array<int, string>
     */
    protected function reviewWithModel(string $system, string $jobBlock, string $draft, string $reviewModel, Lead $lead): array
    {
        try {
            $response = $this->ai->complete(
                'proposal_review',
                $system,
                $jobBlock
                    ."\n\nDRAFT PROPOSAL TO REVIEW:\n<<<DRAFT\n{$draft}\nDRAFT>>>\n\n"
                    .'You are now the REVIEWER, not the writer. Check the draft against EVERY rule in your instructions: hard rules, structure (envelope line, one real-project proof, mini-plan with "Done =", one closing question), banned vocabulary, banned openers, word count, voice, and the final self-check checklist. Also judge these as an editor:'
                    ."\n1. Three-item parallel lists (tricolons) are banned in any form."
                    ."\n2. The \"Done =\" line must name an observable, testable outcome. It must never promise \"zero risk\" or any guaranteed result."
                    ."\n3. No dead-stop sentences that close neatly without opening a loop or adding information."
                    ."\n4. Line 1 must quote or closely paraphrase at least one real, specific detail or named requirement from THIS job post. If it could be pasted unchanged into a different job's proposal, that is a violation."
                    ."\n5. No implied discount, no \"I'm affordable\", no apology for being new, no urgency around price."
                    ."\nOutput JSON only: {\"pass\": true/false, \"violations\": [{\"rule\": \"short rule name\", \"quote\": \"the offending text\", \"reason\": \"one plain sentence\"}]}. If the draft follows every rule, output {\"pass\": true, \"violations\": []}. No other text.",
                $reviewModel,
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

        $violations = [];

        foreach ((array) ($decoded['violations'] ?? []) as $violation) {
            $rendered = is_array($violation)
                ? trim(sprintf(
                    '%s: the text "%s" breaks this rule. %s',
                    $this->scrub((string) ($violation['rule'] ?? 'RULE')),
                    $this->scrub((string) ($violation['quote'] ?? '')),
                    $this->scrub((string) ($violation['reason'] ?? '')),
                ))
                : $this->scrub(trim((string) $violation));

            if ($rendered !== '') {
                $violations[] = $rendered;
            }
        }

        // pass=false with nothing actionable is treated as a pass — a
        // revision with no instructions would just be a reroll.
        return array_slice($violations, 0, 10);
    }

    /**
     * Reviewer-authored text goes back to the writer, so banned dash
     * styles are scrubbed out of it first (models imitate what they read).
     */
    protected function scrub(string $text): string
    {
        $clean = str_replace(['—', '–', '--'], ', ', $text);

        return trim(preg_replace('/\s*,(\s*,)+\s*/', ', ', preg_replace('/\s{2,}/', ' ', $clean) ?? '') ?? '');
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
