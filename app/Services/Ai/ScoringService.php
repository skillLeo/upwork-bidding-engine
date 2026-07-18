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

    public function __construct(
        protected SettingsService $settings,
        protected AiManager $ai,
    ) {}

    /**
     * @return array{score: int, bid: bool, boost: bool, reason: string, response: AiResponse}
     */
    public function score(Lead $lead): array
    {
        $system = trim((string) $this->settings->get('scoring_system_prompt'));

        if ($system === '') {
            throw new \RuntimeException(
                'scoring_system_prompt is empty — paste your scoring rules into Settings → AI models & prompts before scoring.'
            );
        }

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

        return [...$parsed, 'response' => $response];
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
     * @return array{score: int, bid: bool, boost: bool, reason: string}|null
     */
    protected function parse(string $text): ?array
    {
        $stripped = trim(preg_replace('/^```(?:json)?\s*|```\s*$/i', '', trim($text)) ?? '');

        $data = json_decode($stripped, true);

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
        ];
    }
}
