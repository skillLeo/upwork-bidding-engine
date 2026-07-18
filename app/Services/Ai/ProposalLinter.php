<?php

namespace App\Services\Ai;

use App\Services\SettingsService;

/**
 * The mechanical half of the proposal quality gate. Every check here is
 * objective (string-level), so it never "judges" writing — it only catches
 * rule breaks a regex can prove: banned phrases/em dashes, word count,
 * required phrases, the signature line, and off-platform contact info.
 * The subjective rules (slippery slide, awareness stage, one-project
 * proof) are checked by the model's own review pass in ProposalService.
 *
 * All the DATA (lists, bounds, signature) comes from Settings — this
 * class is pure mechanism, per the operator's rules-live-in-settings rule.
 */
class ProposalLinter
{
    public function __construct(protected SettingsService $settings) {}

    /**
     * @return array<int, string> Human-readable violations, empty = clean.
     */
    public function check(string $text): array
    {
        $gate = $this->settings->proposalGate();
        $violations = [];

        foreach ($gate['banned_phrases'] as $phrase) {
            if ($this->containsPhrase($text, $phrase)) {
                $violations[] = $phrase === '—' || $phrase === '–'
                    ? 'Contains an em/en dash ("'.$phrase.'") — banned; use commas, periods, or parentheses instead.'
                    : 'Contains banned phrase "'.$phrase.'".';
            }
        }

        $words = $this->wordCount($text);

        if ($gate['min_words'] > 0 && $words < $gate['min_words']) {
            $violations[] = "Too short: {$words} words — the cover letter body must be {$gate['min_words']}-{$gate['max_words']} words.";
        }

        if ($gate['max_words'] > 0 && $words > $gate['max_words']) {
            $violations[] = "Too long: {$words} words — the cover letter body must be {$gate['min_words']}-{$gate['max_words']} words. Cut everything that fails the \"so what?\" test.";
        }

        foreach ($gate['required_phrases'] as $phrase) {
            if (mb_stripos($text, $phrase) === false) {
                $violations[] = 'Missing required element "'.$phrase.'" — the mini-plan must end in a "Done =" definition of the finished outcome.';
            }
        }

        if ($gate['signature'] !== '' && ! $this->endsWithSignature($text, $gate['signature'])) {
            $violations[] = 'Must end with the first name "'.$gate['signature'].'" alone on the last line — no signature block, no title.';
        }

        foreach ($this->contactInfoViolations($text) as $violation) {
            $violations[] = $violation;
        }

        return $violations;
    }

    public function wordCount(string $text): int
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? 0 : count($words);
    }

    /**
     * Single plain words match on word boundaries (so "art" never flags
     * "part"); anything with spaces or punctuation, including the bare
     * dash characters, matches as a substring.
     */
    protected function containsPhrase(string $text, string $phrase): bool
    {
        if (preg_match('/^[\p{L}\p{N}\'-]+$/u', $phrase) === 1) {
            return preg_match('/\b'.preg_quote($phrase, '/').'\b/iu', $text) === 1;
        }

        return mb_stripos($text, $phrase) !== false;
    }

    protected function endsWithSignature(string $text, string $signature): bool
    {
        $lines = preg_split('/\R/u', trim($text)) ?: [];
        $last = trim((string) end($lines));

        return strcasecmp($last, $signature) === 0;
    }

    /**
     * Hard Upwork-ban territory — an email, phone number, or off-platform
     * channel in a proposal risks the account, so these are never allowed
     * regardless of what the prompt says.
     *
     * @return array<int, string>
     */
    protected function contactInfoViolations(string $text): array
    {
        $violations = [];

        if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $text)) {
            $violations[] = 'Contains an email address — no contact info of any kind is allowed.';
        }

        if (preg_match('/\+\d[\d\s().-]{7,}\d/', $text) || preg_match('/\b\d{3}[-.\s]\d{3}[-.\s]\d{4}\b/', $text)) {
            $violations[] = 'Contains a phone number — no contact info of any kind is allowed.';
        }

        foreach (['whatsapp', 'telegram', 'skype', 'linkedin'] as $channel) {
            if (mb_stripos($text, $channel) !== false) {
                $violations[] = 'Mentions '.$channel.' — no off-platform contact channels are allowed.';
            }
        }

        return $violations;
    }
}
