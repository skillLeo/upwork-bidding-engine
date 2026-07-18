<?php

namespace App\Services\Ai;

use App\Models\Lead;
use App\Services\SettingsService;

/**
 * Writes the proposal — SEQUENTIAL by design: callers must only invoke
 * this after ScoringService returned score >= score_cutoff. Below-cutoff
 * leads are archived with the scoring reason and never reach this class;
 * that ordering (not this file) is what saves ~40% of AI spend.
 *
 * Like scoring, the actual writing rules live in proposal_system_prompt
 * in Settings — operator-owned, browser-editable, never in code.
 */
class ProposalService
{
    // Generous because Sonnet 5 runs adaptive thinking by default and
    // max_tokens bounds thinking + text together.
    public const MAX_TOKENS = 8000;

    public function __construct(
        protected SettingsService $settings,
        protected AiManager $ai,
        protected ScoringService $scoring,
    ) {}

    /**
     * @param  array{score: int, boost: bool, reason: string}  $scoring
     * @return array{text: string, response: AiResponse}
     */
    public function write(Lead $lead, array $scoring): array
    {
        $system = trim((string) $this->settings->get('proposal_system_prompt'));

        if ($system === '') {
            throw new \RuntimeException(
                'proposal_system_prompt is empty — paste your proposal rules into Settings → AI models & prompts before generating proposals.'
            );
        }

        $user = $this->scoring->jobContent($lead)
            ."\n\nThis job scored {$scoring['score']}/10"
            .($scoring['reason'] !== '' ? " — {$scoring['reason']}" : '')
            ."\nWrite the proposal now. Return ONLY the proposal text, nothing else.";

        $model = (string) $this->settings->get('proposal_model', 'claude-sonnet-5');

        $response = $this->ai->complete('proposal', $system, $user, $model, self::MAX_TOKENS, $lead->id);

        $text = trim(preg_replace('/^```\w*\s*|```\s*$/', '', trim($response->text)) ?? '');

        if ($text === '') {
            throw new \RuntimeException('Proposal generation returned empty text.');
        }

        return ['text' => $text, 'response' => $response];
    }
}
