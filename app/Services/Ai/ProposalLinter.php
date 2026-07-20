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
    /**
     * Canonical technology names this check recognizes, each a
     * case-insensitive word-boundary pattern (case-sensitive only where
     * the bare word is also common English, e.g. "React" vs. "react to
     * feedback"). Covers Hassam's real stack (so legitimate mentions are
     * correctly recognized as allowed) plus technologies he has never
     * worked in that models commonly invent for stack-adjacent jobs.
     * Extend this list, not the AI reviewer's prompt, if a new real
     * project introduces a genuinely new technology.
     *
     * @var array<string, string>
     */
    protected const TECH_VOCABULARY = [
        // Hassam's real stack.
        'Laravel' => '/\blaravel\b/i',
        'Vue' => '/\bvue(?:\.js)?\b/i',
        'Flutter' => '/\bflutter\b/i',
        'PostgreSQL' => '/\bpostgres(?:ql)?\b/i',
        'MySQL' => '/\bmysql\b/i',
        'Next.js' => '/\bnext\.?js\b/i',
        'React Native' => '/\breact\s*native\b/i',
        'React' => '/\bReact\b/',
        'Node.js' => '/\bnode(?:\.js)?\b/i',
        'Express' => '/\bexpress(?:\.js)?\b/i',
        'Python' => '/\bpython\b/i',
        'FastAPI' => '/\bfastapi\b/i',
        'Django' => '/\bdjango\b/i',
        'PHP' => '/\bphp\b/i',
        'Odoo' => '/\bodoo\b/i',
        'Stripe' => '/\bstripe\b/i',
        'TypeScript' => '/\btypescript\b/i',
        'Firebase' => '/\bfirebase\b/i',
        'Dart' => '/\bdart\b/i',
        'Inertia.js' => '/\binertia(?:\.js)?\b/i',
        'Spatie' => '/\bspatie\b/i',
        'Blade' => '/\bblade\b/i',
        'Livewire' => '/\blivewire\b/i',
        'Tailwind CSS' => '/\btailwind(?:\s*css)?\b/i',
        // Commonly hallucinated - never part of Hassam's real stack.
        'MongoDB' => '/\bmongo(?:db)?\b/i',
        'Ruby on Rails' => '/\brails\b/i',
        'Ruby' => '/\bruby\b/i',
        'Golang' => '/\bgolang\b/i',
        'Rust' => '/\brust\b/i',
        'Angular' => '/\bangular\b/i',
        'jQuery' => '/\bjquery\b/i',
        'Bootstrap' => '/\bbootstrap\b/i',
        'GraphQL' => '/\bgraphql\b/i',
        'Redis' => '/\bredis\b/i',
        'Kubernetes' => '/\bkubernetes\b|\bk8s\b/i',
        'Docker' => '/\bdocker\b/i',
        'AWS Lambda' => '/\baws\s+lambda\b/i',
        '.NET' => '/\bdotnet\b|\bASP\.NET\b/i',
        'Java' => '/\bjava\b/i',
        'Spring Boot' => '/\bspring\s*boot\b/i',
        'Symfony' => '/\bsymfony\b/i',
        'WordPress' => '/\bwordpress\b/i',
        'Magento' => '/\bmagento\b/i',
        'Shopify' => '/\bshopify\b/i',
        'Wix' => '/\bwix\b/i',
        'Redux' => '/\bredux\b/i',
        'Vuex' => '/\bvuex\b/i',
        'Nuxt' => '/\bnuxt\b/i',
        'Svelte' => '/\bsvelte\b/i',
    ];

    public function __construct(protected SettingsService $settings) {}

    /**
     * @return array<int, string> Human-readable violations, empty = clean.
     */
    public function check(string $text, ?string $jobBrief = null): array
    {
        $gate = $this->settings->proposalGate();
        [$answers, $letter] = $this->segments($text);
        $violations = [];

        foreach ($gate['banned_phrases'] as $phrase) {
            if ($this->containsPhrase($text, $phrase)) {
                $violations[] = $this->bannedMessage($phrase);
            }
        }

        if ($jobBrief !== null && ($violation = $this->trapInstructionViolation($text, $jobBrief)) !== null) {
            $violations[] = $violation;
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
            // Seen live: told only "must be 130 to 250" (no shortfall
            // number), a revision landed at 126, then 127 - repeatedly
            // just under the line rather than clearing it. Models are
            // unreliable at precisely counting their own output, so the
            // instruction now does the arithmetic and asks for a safety
            // margin past the minimum, not an exact hit on it.
            $shortfall = $gate['min_words'] - $words;
            $target = $gate['min_words'] + max(5, (int) ceil($shortfall * 0.5));
            $violations[] = "Cover letter too short: {$words} words, {$shortfall} short of the {$gate['min_words']}-word minimum. Add real, relevant detail (never filler) until the letter is at least {$target} words - overshoot the minimum, don't land exactly on it. Recount before answering.";
        }

        if ($gate['max_words'] > 0 && $words > $gate['max_words']) {
            $overage = $words - $gate['max_words'];
            $target = max($gate['min_words'], $gate['max_words'] - max(5, (int) ceil($overage * 0.5)));
            $violations[] = "Cover letter too long: {$words} words, {$overage} over the {$gate['max_words']}-word maximum. Cut every sentence that fails the \"so what?\" test until the letter is at or under {$target} words - undershoot the maximum, don't land exactly on it. Recount before answering.";
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

        foreach ($this->techClaimViolations($letter) as $violation) {
            $violations[] = $violation;
        }

        return $violations;
    }

    /**
     * Real incident this closes: a proposal cited "MongoDB schemas,
     * Node.js, Express" as part of PatrolTick, whose real stack per
     * project_facts is Laravel + Vue + Flutter + PostgreSQL. Neither the
     * mechanical checks above nor the AI reviewer's prompt ever compared
     * proposal text against the fact sheet - this does, deterministically,
     * word-list against word-list, no AI judgment involved.
     *
     * Two passes: (1) a technology named anywhere that never appears
     * anywhere in project_facts at all is an outright fabrication; (2) a
     * technology attributed to a SPECIFIC named project, in the same
     * paragraph as that project's name, that isn't part of THAT project's
     * own listed stack - even if the technology is real elsewhere in
     * Hassam's history (e.g. Node/Express are genuine general skills per
     * the sheet's aggregate Stack line, but PatrolTick specifically never
     * used them).
     *
     * @return array<int, string>
     */
    protected function techClaimViolations(string $letter): array
    {
        if (trim($letter) === '') {
            return [];
        }

        $projectFacts = (string) $this->settings->get('project_facts', '');

        if (trim($projectFacts) === '') {
            return [];
        }

        $violations = [];
        $flagged = [];

        foreach (self::TECH_VOCABULARY as $tech => $pattern) {
            if (preg_match($pattern, $letter) !== 1) {
                continue;
            }

            if (preg_match($pattern, $projectFacts) !== 1) {
                $violations[] = "Fabricated tech claim: \"{$tech}\" does not appear anywhere in your project facts and must never be claimed. Name only technology that is actually on the fact sheet, or drop the claim.";
                $flagged[$tech] = true;
            }
        }

        $projects = $this->parseProjectFacts($projectFacts);

        foreach (preg_split('/\n\s*\n/u', trim($letter)) ?: [] as $paragraph) {
            foreach ($projects as $name => $stackLine) {
                if (mb_stripos($paragraph, $name) === false) {
                    continue;
                }

                foreach (self::TECH_VOCABULARY as $tech => $pattern) {
                    if (isset($flagged[$tech]) || preg_match($pattern, $paragraph) !== 1) {
                        continue;
                    }

                    if (preg_match($pattern, $stackLine) !== 1) {
                        $violations[] = "Project-stack mismatch: \"{$tech}\" is attributed to {$name}, but {$name}'s real stack per project_facts does not include it. Never attribute technology to a named project it wasn't built with, even if that technology is genuine elsewhere in your history.";
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * @return array<string, string> project name => its own fact-sheet line
     */
    protected function parseProjectFacts(string $projectFacts): array
    {
        $projects = [];

        foreach (preg_split('/\R/u', $projectFacts) ?: [] as $line) {
            if (preg_match('/^([A-Za-z][\w &.\/\-]{1,40}):\s/u', trim($line), $m) !== 1) {
                continue;
            }

            $name = trim($m[1]);

            // The aggregate skills line and any prose disclaimer aren't
            // named projects - only lines that open with a real project
            // name are checked for project-specific stack attribution.
            if ($name === 'Stack' || str_starts_with($name, 'Technologies NOT')) {
                continue;
            }

            $projects[$name] = $line;
        }

        return $projects;
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

    /**
     * Seen live: a job post said "Start your reply with CARE-STACK so we
     * know you read the full post" and the shipped proposal ignored it
     * entirely - missed by both the writer and the AI reviewer, even
     * though the skill's own self-check checklist already says to obey
     * trap instructions exactly. A client's literal opening-word trap is
     * exactly as mechanically detectable and checkable as the signature
     * line, so it belongs here rather than staying a hope that the model
     * remembers on every draft.
     */
    protected function trapInstructionViolation(string $text, string $jobBrief): ?string
    {
        $required = self::requiredOpeningWord($jobBrief);

        if ($required === null) {
            return null;
        }

        $actualStart = mb_substr(ltrim($text), 0, mb_strlen($required));

        if (strcasecmp($actualStart, $required) === 0) {
            return null;
        }

        return 'Must start with "'.$required.'" exactly. The job post gives this as a literal instruction to prove the post was read; ignoring it risks the whole application being discarded.';
    }

    /**
     * Public and static so ProposalService can use the exact same detection
     * to mechanically prepend the word when a revision still misses it,
     * rather than duplicating this regex and risking the two drifting apart.
     */
    public static function requiredOpeningWord(string $jobBrief): ?string
    {
        if (preg_match(
            '/\b(?:start|begin)(?:ing)?\s+(?:your\s+|the\s+)?(?:reply|response|proposal|application|message|bid|cover\s*letter)?\s*(?:with|by\s+(?:writing|typing|saying))\s+(?:the\s+word\s+)?["\x{201C}\x{2018}]?([A-Za-z][A-Za-z0-9\-]{1,30})["\x{201D}\x{2019}]?/iu',
            $jobBrief,
            $matches,
        ) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
