<?php

namespace App\Services\Ai;

use App\Models\Lead;
use App\Services\SettingsService;

/**
 * Scores a lead against the operator's own rules. The rules are NOT in
 * this file — they live in the scoring_system_prompt setting, editable in
 * the browser, pasted and tuned by the operator. This class only owns the
 * plumbing: build the per-lead user content, call the model, parse and
 * validate the JSON, retry once on garbage, and fail loudly rather than
 * ever guessing a score.
 */
class ScoringService
{
    public const MAX_TOKENS = 1000;

    /**
     * Output plumbing appended after the rubric (same precedent as
     * ProposalService::FORMAT_SPEC living in code): the five pillar
     * sub-scores make a 6 explainable on the lead page. The overall
     * score and verdict still follow the rubric's weights and gates.
     */
    public const SUB_SCORES_SPEC = 'ADDITIONAL OUTPUT REQUIREMENT: besides the keys your output format already requires, include a "sub_scores" object rating each pillar 1-10: {"client_quality": n, "competition": n, "stack_fit": n, "budget": n, "post_quality": n}. These explain the overall score; the overall score and verdict still follow the rubric.';

    public function __construct(
        protected SettingsService $settings,
        protected AiManager $ai,
    ) {}

    /**
     * @return array{score: int, bid: bool, boost: bool, reason: string, sub_scores: array<string, int>|null, response: AiResponse}
     */
    public function score(Lead $lead): array
    {
        $system = $this->systemPrompt();
        $user = $this->jobContent($lead);
        $model = (string) $this->settings->get('scoring_model', 'claude-haiku-4-5');

        $response = $this->ai->complete('scoring', $system, $user, $model, self::MAX_TOKENS, $lead->id);
        $parsed = $this->parse($response->text);

        if ($parsed === null) {
            // One corrective retry, then fail loudly — a guessed score is
            // worse than no score.
            $response = $this->ai->complete(
                'scoring',
                $system,
                $user."\n\nYour previous output could not be parsed. Return ONLY the raw JSON object — no markdown fences, no commentary, no text before or after it.",
                $model,
                self::MAX_TOKENS,
                $lead->id,
            );
            $parsed = $this->parse($response->text);
        }

        if ($parsed === null) {
            throw new \RuntimeException(
                'AI returned malformed scoring output after a retry — refusing to guess. Raw output: '.mb_substr($response->text, 0, 500)
            );
        }

        // Stage 1 (new account): the rubric's thresholds allow BOOST at
        // 9+, but its own Stage 1 recommendation says never boost yet.
        // The recommendation wins — force it off regardless of what the
        // model returned, until the operator flips to stage 2.
        if ($this->settings->get('account_stage', 'stage_1_new') !== 'stage_2_established') {
            $parsed['boost'] = false;
        }

        return [...$parsed, 'response' => $response];
    }

    /**
     * The static block every scoring call sends: the operator's rubric,
     * the sub-scores output spec, and (in stage 2 only) the stage
     * addendum. Public so the operator can print and diff exactly what
     * each stage sends — stage 1 and stage 2 differ by the addendum
     * alone, byte for byte.
     */
    public function systemPrompt(): string
    {
        $rubric = trim((string) $this->settings->get('scoring_system_prompt'));

        if ($rubric === '') {
            throw new \RuntimeException(
                'scoring_system_prompt is empty — paste your scoring rules into Settings → AI models & prompts before scoring.'
            );
        }

        $system = $rubric."\n\n".self::SUB_SCORES_SPEC;

        if ($this->settings->get('account_stage', 'stage_1_new') === 'stage_2_established') {
            $addendum = trim((string) $this->settings->get('stage_2_scoring_addendum'));

            if ($addendum !== '') {
                $system .= "\n\n".$addendum;
            }
        }

        return $system;
    }

    /**
     * The per-lead variable content. Kept AFTER the cached system block so
     * the long rules prefix stays byte-identical across calls. The heading
     * names the task: scoring keeps the default; the proposal writer passes
     * its own so a writing call is never framed as a scoring call.
     */
    public function jobContent(Lead $lead, string $heading = 'Score this Upwork job:'): string
    {
        $lines = [
            $heading,
            '',
            'TITLE: '.$lead->title,
            'BUDGET: '.($lead->budget ?? 'not specified').($lead->budget_type ? " ({$lead->budget_type})" : ''),
            'POSTED: '.($lead->posted_at?->diffForHumans() ?? 'unknown'),
            'PROPOSALS SO FAR: '.$lead->proposal_count,
            'CONNECTS REQUIRED: '.($lead->connects_required ?? 'unknown'),
            'SKILLS: '.(filled($lead->skills) ? implode(', ', $lead->skills) : 'not listed'),
            'CLIENT: '.($lead->client_country ?? 'unknown country')
                .' · total spent '.($lead->client_spend ?? 'unknown')
                .' · hire rate '.($lead->client_hire_rate ?? 'unknown')
                .' · '.($lead->payment_verified ? 'payment verified' : 'payment NOT verified')
                .($lead->client_rating ? " · rating {$lead->client_rating}" : ''),
            '',
            'FULL BRIEF:',
            (string) $lead->full_brief,
        ];

        return implode("\n", $lines);
    }

    /**
     * @return array{score: int, bid: bool, boost: bool, reason: string, sub_scores: array<string, int>|null}|null
     */
    protected function parse(string $text): ?array
    {
        $stripped = trim(preg_replace('/^```(?:json)?\s*|```\s*$/i', '', trim($text)) ?? '');

        $data = json_decode($stripped, true);

        if (! is_array($data)) {
            // Models sometimes append commentary after the JSON (seen live:
            // a fenced object followed by "**Notes:** ..."). Extract the
            // outermost object before refusing.
            $start = strpos($stripped, '{');
            $end = strrpos($stripped, '}');

            if ($start !== false && $end !== false && $end > $start) {
                $data = json_decode(substr($stripped, $start, $end - $start + 1), true);
            }
        }

        if (! is_array($data) || ! isset($data['score']) || ! is_numeric($data['score'])) {
            return null;
        }

        $score = (int) $data['score'];

        if ($score < 1 || $score > 10) {
            return null;
        }

        $cutoff = (int) $this->settings->get('score_cutoff', 7);

        return [
            'score' => $score,
            'bid' => (bool) ($data['bid'] ?? $score >= $cutoff),
            'boost' => (bool) ($data['boost'] ?? $score >= 9),
            'reason' => (string) ($data['reason'] ?? ''),
            'sub_scores' => $this->parseSubScores($data),
        ];
    }

    /**
     * Explainability only — a missing or malformed sub_scores block never
     * fails a scoring run; the verdict fields above are the contract.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int>|null
     */
    protected function parseSubScores(array $data): ?array
    {
        if (! isset($data['sub_scores']) || ! is_array($data['sub_scores'])) {
            return null;
        }

        $subScores = [];

        foreach (['client_quality', 'competition', 'stack_fit', 'budget', 'post_quality'] as $key) {
            if (isset($data['sub_scores'][$key]) && is_numeric($data['sub_scores'][$key])) {
                $subScores[$key] = max(1, min(10, (int) $data['sub_scores'][$key]));
            }
        }

        return $subScores === [] ? null : $subScores;
    }
}
