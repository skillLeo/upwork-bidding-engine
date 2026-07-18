<?php

namespace App\Services\Ai;

use App\Services\SettingsService;

/**
 * The mechanical half of the proposal quality gate. Every check here is
 * objective (string-level), so it never "judges" writing. It only catches
 * rule breaks a regex can prove: banned phrases and dash characters,
 * markdown artifacts, word count, duplication, rhythm, required phrases,
 * the signature line, and off-platform contact info. The subjective rules
 * (slippery slide, awareness stage, one-project proof, tricolons) are
 * checked by the model's review pass in ProposalService.
 *
 * All the DATA (lists, bounds, signature) comes from Settings; this class
 * is pure mechanism, per the operator's rules-live-in-settings rule.
 *
 * STYLE CONTRACT for every message string in this file: violation text is
 * fed back to the writing model verbatim, and models imitate what they
 * read. So no em dashes, no en dashes, no three-item parallel lists, no
 * banned vocabulary. Name special characters, never demonstrate them.
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
        [$answers, $letter] = $this->segments($text);
        $violations = [];

        foreach ($gate['banned_phrases'] as $phrase) {
            if ($this->containsPhrase($text, $phrase)) {
                $violations[] = $this->bannedMessage($phrase);
            }
        }

        foreach ($this->markdownArtifactViolations($text) as $violation) {
            $violations[] = $violation;
        }

        if (preg_match('/^[^\p{L}\p{N}"\'(]/u', ltrim($text)) === 1) {
            $violations[] = 'Starts with stray punctuation. The first character must begin a real sentence.';
        }

        // Word bounds apply to the LETTER BODY only, never to screening
        // answers (those are as long as the questions demand).
        $words = $this->wordCount($letter);

        if ($gate['min_words'] > 0 && $words < $gate['min_words']) {
            $violations[] = "Cover letter too short: {$words} words. It must be {$gate['min_words']} to {$gate['max_words']} words.";
        }

        if ($gate['max_words'] > 0 && $words > $gate['max_words']) {
            $violations[] = "Cover letter too long: {$words} words. It must be {$gate['min_words']} to {$gate['max_words']} words. Cut every sentence that fails the \"so what?\" test.";
        }

        foreach ($gate['required_phrases'] as $phrase) {
            if (mb_stripos($text, $phrase) === false) {
                $violations[] = 'Missing required element "'.$phrase.'". The mini-plan must end in a "Done =" definition of the finished outcome.';
            }
        }

        if ($gate['signature'] !== '' && ! $this->endsWithSignature($text, $gate['signature'])) {
            $violations[] = 'Must end with the first name "'.$gate['signature'].'" alone on the last line, with no signature block and no title.';
        }

        if ($duplicate = $this->duplicatedFragment($answers, $letter)) {
            $violations[] = 'Repeats itself: "'.$duplicate.'" appears more than once. Say each thing exactly once.';
        }

        // The bare signature line would trivially satisfy this, so it's
        // stripped before the rhythm check.
        if ($letter !== '' && ! $this->hasShortSentence($this->withoutSignature($letter, $gate['signature']))) {
            $violations[] = 'No short sentence anywhere. Include at least one sentence of 8 words or fewer to vary the rhythm.';
        }

        foreach ($this->contactInfoViolations($text) as $violation) {
            $violations[] = $violation;
        }

        return $violations;
    }

    /**
     * Split the output into [screening answers, letter body]. Answers are
     * only detectable when they lead the output as numbered paragraphs
     * (the format spec numbers them only when the client numbered their
     * questions); otherwise the whole text is the letter.
     *
     * @return array{0: string, 1: string}
     */
    public function segments(string $text): array
    {
        $paragraphs = preg_split('/\n\s*\n/u', trim($text)) ?: [];
        $answers = [];

        while ($paragraphs !== [] && preg_match('/^\s*(?:\d+[.)]\s|Q\d|Answer\s+\d)/iu', $paragraphs[0]) === 1) {
            $answers[] = array_shift($paragraphs);
        }

        return [implode("\n\n", $answers), implode("\n\n", $paragraphs)];
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
        // Word-boundary matching needs at least one word character —
        // pure-punctuation entries like the double hyphen must go the
        // substring route or \b never anchors.
        if (preg_match('/^[\p{L}\p{N}\'-]+$/u', $phrase) === 1 && preg_match('/[\p{L}\p{N}]/u', $phrase) === 1) {
            return preg_match('/\b'.preg_quote($phrase, '/').'\b/iu', $text) === 1;
        }

        return mb_stripos($text, $phrase) !== false;
    }

    /**
     * Names the character instead of demonstrating it, so the feedback
     * never shows the model the banned style.
     */
    protected function bannedMessage(string $phrase): string
    {
        return match ($phrase) {
            '—' => 'Contains an em dash character. Replace it with a comma, a period, or parentheses.',
            '–' => 'Contains an en dash character. Replace it with a comma, a period, or parentheses.',
            '--' => 'Contains a double hyphen. Replace it with a comma, a period, or parentheses.',
            default => 'Contains banned phrase "'.$phrase.'". Remove it.',
        };
    }

    /**
     * @return array<int, string>
     */
    protected function markdownArtifactViolations(string $text): array
    {
        $violations = [];

        if (preg_match('/^\s*-{3,}\s*$/m', $text)) {
            $violations[] = 'Contains a horizontal-rule separator line. Plain text only, no separators.';
        }

        if (str_contains($text, '**')) {
            $violations[] = 'Contains markdown bold markers. Plain text only.';
        }

        if (preg_match('/^\s*#{1,6}\s/m', $text)) {
            $violations[] = 'Contains a markdown heading. Plain text only, no headers.';
        }

        if (str_contains($text, '`')) {
            $violations[] = 'Contains backticks. Plain text only, no code formatting.';
        }

        return $violations;
    }

    /**
     * Finds material said twice: any normalized sentence of 4+ words, or
     * any 8-word sequence, appearing twice within the letter or in both
     * the answers and the letter. Returns the fragment, or null if clean.
     */
    protected function duplicatedFragment(string $answers, string $letter): ?string
    {
        $sentences = fn (string $t): array => array_values(array_filter(
            array_map(
                fn (string $s) => trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', mb_strtolower($s)) ?? ''),
                preg_split('/[.!?]+/u', $t) ?: [],
            ),
            fn (string $s) => $this->wordCount($s) >= 4,
        ));

        $letterSentences = $sentences($letter);

        foreach (array_count_values($letterSentences) as $sentence => $count) {
            if ($count > 1) {
                return $this->shorten((string) $sentence);
            }
        }

        $answerSentences = $sentences($answers);

        if ($answers !== '' && ($shared = array_intersect($letterSentences, $answerSentences)) !== []) {
            return $this->shorten((string) reset($shared));
        }

        $letterGrams = $this->wordGrams($letter);

        foreach (array_count_values($letterGrams) as $gram => $count) {
            if ($count > 1) {
                return $this->shorten((string) $gram);
            }
        }

        if ($answers !== '' && ($shared = array_intersect($letterGrams, $this->wordGrams($answers))) !== []) {
            return $this->shorten((string) reset($shared));
        }

        return null;
    }

    /**
     * All normalized 8-word sequences in the text.
     *
     * @return array<int, string>
     */
    protected function wordGrams(string $text): array
    {
        $words = preg_split(
            '/\s+/u',
            trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', mb_strtolower($text)) ?? ''),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];

        $grams = [];

        for ($i = 0; $i + 8 <= count($words); $i++) {
            $grams[] = implode(' ', array_slice($words, $i, 8));
        }

        return $grams;
    }

    protected function shorten(string $fragment): string
    {
        return mb_strlen($fragment) > 60 ? mb_substr($fragment, 0, 57).'...' : $fragment;
    }

    protected function hasShortSentence(string $letter): bool
    {
        foreach (preg_split('/[.!?]+/u', $letter) ?: [] as $sentence) {
            $count = $this->wordCount($sentence);

            if ($count > 0 && $count <= 8) {
                return true;
            }
        }

        return false;
    }

    protected function withoutSignature(string $letter, string $signature): string
    {
        if ($signature === '') {
            return $letter;
        }

        $lines = preg_split('/\R/u', rtrim($letter)) ?: [];

        if ($lines !== [] && strcasecmp(trim((string) end($lines)), $signature) === 0) {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    protected function endsWithSignature(string $text, string $signature): bool
    {
        $lines = preg_split('/\R/u', trim($text)) ?: [];
        $last = trim((string) end($lines));

        return strcasecmp($last, $signature) === 0;
    }

    /**
     * Hard Upwork-ban territory. An email, phone number, or off-platform
     * channel in a proposal risks the account, so these are never allowed
     * regardless of what the prompt says.
     *
     * @return array<int, string>
     */
    protected function contactInfoViolations(string $text): array
    {
        $violations = [];

        if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $text)) {
            $violations[] = 'Contains an email address. No contact info of any kind is allowed.';
        }

        if (preg_match('/\+\d[\d\s().-]{7,}\d/', $text) || preg_match('/\b\d{3}[-.\s]\d{3}[-.\s]\d{4}\b/', $text)) {
            $violations[] = 'Contains a phone number. No contact info of any kind is allowed.';
        }

        foreach (['whatsapp', 'telegram', 'skype', 'linkedin'] as $channel) {
            if (mb_stripos($text, $channel) !== false) {
                $violations[] = 'Mentions '.$channel.'. No off-platform contact channels are allowed.';
            }
        }

        return $violations;
    }
}
