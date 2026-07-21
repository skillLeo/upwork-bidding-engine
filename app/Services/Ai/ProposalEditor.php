<?php

namespace App\Services\Ai;

use App\Models\Lead;
use App\Services\SettingsService;

/**
 * AI-assisted proposal editing, preview-only. Two modes:
 *
 *  - SELECTION: the operator highlighted a span. The model is given the full
 *    proposal for context but constrained to return JSON with only the
 *    replacement text for that span, which is spliced back in server-side.
 *    This is the reliable shape - the model never rewrites untouched text.
 *
 *  - WHOLE: no selection. The model rewrites the entire proposal against the
 *    instruction, using the SAME cached system block the writer uses (so
 *    prompt caching still hits) and therefore the same rules.
 *
 * preview() never persists. The result is linted before it is returned, so a
 * bad edit is caught in the preview instead of slipping into a saved version.
 * Persisting is a separate, explicit accept step (see LeadController).
 */
class ProposalEditor
{
    public const MAX_TOKENS = 1500;

    /** Static so the span-edit system block caches across calls. */
    protected const SELECTION_SYSTEM = 'You are a precise copy editor for freelance proposals. You edit ONLY the text between the markers. You never change, re-order, or rephrase anything outside the markers. You return JSON only.';

    public function __construct(
        protected AiManager $ai,
        protected ProposalLinter $linter,
        protected ProposalService $proposals,
        protected SettingsService $settings,
    ) {}

    /**
     * @return array{old_text: string, new_text: string, edit_type: string, linter_violations: array<int, string>, model: string, cost: float|null}
     *
     * @throws ProposalEditFailedException
     */
    public function preview(Lead $lead, string $instruction, ?int $start, ?int $end): array
    {
        $body = (string) $lead->proposal_text;

        if (trim($body) === '') {
            throw new ProposalEditFailedException('There is no proposal to edit yet.');
        }

        if ($start !== null && $end !== null) {
            [$newBody, $response] = $this->selectionEdit($lead, $body, $instruction, $start, $end);
            $editType = 'ai_surgical_edit';
        } else {
            [$newBody, $response] = $this->wholeEdit($lead, $body, $instruction);
            $editType = 'ai_instructed_rewrite';
        }

        // The full safeguard funnel runs on the RESULT before it is ever shown,
        // so an edit that reintroduced a banned claim is flagged in the preview.
        return [
            'old_text' => $body,
            'new_text' => $newBody,
            'edit_type' => $editType,
            'linter_violations' => array_values($this->linter->check($newBody, (string) $lead->full_brief)),
            'model' => $response->model,
            'cost' => $response->costUsd,
        ];
    }

    /**
     * @return array{0: string, 1: \App\Services\Ai\AiResponse}
     *
     * @throws ProposalEditFailedException
     */
    protected function selectionEdit(Lead $lead, string $body, string $instruction, int $start, int $end): array
    {
        $len = mb_strlen($body);

        if ($start < 0 || $end > $len || $start >= $end) {
            throw new ProposalEditFailedException('The selected range is invalid. Reselect the text and try again.');
        }

        $selected = mb_substr($body, $start, $end - $start);

        $user = "Here is the full proposal for context:\n\"\"\"\n{$body}\n\"\"\"\n\n"
            ."Edit ONLY this selected span:\n<<<SELECTION>>>{$selected}<<<END>>>\n\n"
            ."Instruction: {$instruction}\n\n"
            .'Return strict JSON: {"replacement":"<new text for the selected span only>"}. Do not include the markers. Do not return any text outside the JSON.';

        $response = $this->ai->complete(
            'proposal_ai_edit',
            self::SELECTION_SYSTEM,
            $user,
            (string) $this->settings->get('proposal_model', 'claude-sonnet-5'),
            self::MAX_TOKENS,
            $lead->id,
        );

        $replacement = $this->parseReplacement($response->text);
        $newBody = mb_substr($body, 0, $start).$replacement.mb_substr($body, $end);

        return [$newBody, $response];
    }

    /**
     * @return array{0: string, 1: \App\Services\Ai\AiResponse}
     */
    protected function wholeEdit(Lead $lead, string $body, string $instruction): array
    {
        // The writer's exact system block - same rules, same bytes, so the
        // prompt cache still hits on the shared static prefix.
        $system = $this->proposals->systemPrompt();

        $user = "Here is the current proposal:\n\"\"\"\n{$body}\n\"\"\"\n\n"
            ."Revise the ENTIRE proposal to satisfy this instruction, while still following every rule in your instructions:\n{$instruction}\n\n"
            .'Return ONLY the revised proposal text, nothing else.';

        $response = $this->ai->complete(
            'proposal_ai_edit',
            $system,
            $user,
            (string) $this->settings->get('proposal_model', 'claude-sonnet-5'),
            self::MAX_TOKENS,
            $lead->id,
        );

        return [trim($response->text), $response];
    }

    /**
     * Strictly parse the {"replacement": "..."} object. A model that returns
     * anything else is a failure, not something to guess at - the caller turns
     * this into a 422 and applies nothing.
     *
     * @throws ProposalEditFailedException
     */
    protected function parseReplacement(string $text): string
    {
        $stripped = trim(preg_replace('/^```(?:json)?\s*|```\s*$/i', '', trim($text)) ?? '');

        $data = json_decode($stripped, true);

        if (! is_array($data)) {
            $open = strpos($stripped, '{');
            $close = strrpos($stripped, '}');

            if ($open !== false && $close !== false && $close > $open) {
                $data = json_decode(substr($stripped, $open, $close - $open + 1), true);
            }
        }

        if (! is_array($data) || ! array_key_exists('replacement', $data) || ! is_string($data['replacement'])) {
            throw new ProposalEditFailedException('The AI returned an unreadable edit, so nothing was changed. Try rephrasing the instruction.');
        }

        return $data['replacement'];
    }
}
