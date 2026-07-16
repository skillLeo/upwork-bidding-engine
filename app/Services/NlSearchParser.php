<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Turns a free-typed or spoken leads search ("laravel jobs over $500,
 * verified clients, no wordpress") into structured filter criteria - no AI
 * call, no tokens, just pattern matching against phrasings the bidder
 * actually uses. LeadController only falls back to OpenClaw when parse()
 * returns understood=false, which happens only when nothing recognizable
 * (not even leftover free text) was found.
 *
 * Each extraction step consumes the phrase(s) it matched out of a working
 * copy of the query, so: (1) leftover unrecognized text still becomes a
 * plain keyword search instead of being silently dropped, and (2) later
 * steps never re-match text an earlier, more specific step already claimed
 * (e.g. "under 10 proposals" is claimed by the proposals step before the
 * generic budget "under $X" step ever sees it).
 */
class NlSearchParser
{
    public function __construct(protected SettingsService $settings) {}

    /**
     * Canonical stack keyword => phrase variants, including common Web
     * Speech API mishearings of these exact words (confirmed against real
     * dictation: "level" for "Laravel", "man"/"murn" for "MERN", etc).
     * Matched as whole words only, longest phrase first, so multi-word
     * variants aren't shadowed by a shorter one.
     *
     * @var array<string, array<int, string>>
     */
    protected array $stackSynonyms = [
        'Laravel' => ['laravel', 'level', 'laravelle'],
        'PHP' => ['php'],
        'Next.js' => ['next.js', 'next js', 'nextjs'],
        'React Native' => ['react native', 'reactnative'],
        'React' => ['react'],
        'Node' => ['node.js', 'node js', 'node'],
        'MERN' => ['mern', 'man', 'murn'],
        'Flutter' => ['flutter'],
        'Django' => ['django'],
        'FastAPI' => ['fastapi', 'fast api'],
        'Python' => ['python'],
        'Vue' => ['vue.js', 'vue js', 'vue'],
        'TypeScript' => ['typescript', 'type script'],
        'MySQL' => ['mysql', 'my sql'],
        'PostgreSQL' => ['postgresql', 'postgres sql', 'postgres', 'post grass'],
        'Firebase' => ['firebase'],
        'Odoo' => ['odoo', 'oh do', 'audio', 'odo'],
        'WordPress' => ['wordpress', 'word press'],
        'Shopify' => ['shopify'],
        'Wix' => ['wix'],
    ];

    /**
     * @var array<string, string>
     */
    protected array $countrySynonyms = [
        'us' => 'United States', 'usa' => 'United States', 'united states' => 'United States', 'america' => 'United States',
        'uk' => 'United Kingdom', 'britain' => 'United Kingdom', 'united kingdom' => 'United Kingdom',
        'uae' => 'United Arab Emirates', 'united arab emirates' => 'United Arab Emirates',
        'canada' => 'Canada', 'australia' => 'Australia', 'germany' => 'Germany',
        'pakistan' => 'Pakistan', 'india' => 'India', 'netherlands' => 'Netherlands',
        'new zealand' => 'New Zealand', 'nz' => 'New Zealand',
    ];

    /**
     * Filler words dropped from whatever's left over after every pattern
     * above has claimed its matches - what remains becomes a keyword
     * search, so these never should.
     *
     * @var array<int, string>
     */
    protected array $stopwords = [
        'show', 'me', 'the', 'of', 'who', 'have', 'that', 'is', 'are', 'a', 'an',
        'and', 'with', 'jobs', 'job', 'leads', 'lead', 'clients', 'client', 'please',
        'find', 'search', 'for', 'give', 'want', 'to', 'i', 'my', 'any', 'all', 'also',
        'but', 'just', 'only', 'them', 'those', 'these',
    ];

    /**
     * @return array{criteria: array<string, mixed>, chips: array<int, array{label: string, phrase: string}>, understood: bool}
     */
    public function parse(string $query): array
    {
        $remaining = ' '.trim($query).' ';
        $criteria = [];
        $chips = [];

        // Fixed multi-word phrases first, longest first, so a more specific
        // phrase is never left half-claimed by a shorter generic one below.
        $this->extractFixedPhrases($remaining, $criteria, $chips);

        // Specific numeric domains (proposals/connects/hire-rate/score) run
        // before the generic budget over/under extraction, since they share
        // the same "under N" / "above N" wording and would otherwise get
        // misread as a budget figure.
        $this->extractProposalMax($remaining, $criteria, $chips);
        $this->extractConnectsMax($remaining, $criteria, $chips);
        $this->extractHireRate($remaining, $criteria, $chips);
        $this->extractScore($remaining, $criteria, $chips);
        $this->extractBudgetBetween($remaining, $criteria, $chips);
        $this->extractBudgetOver($remaining, $criteria, $chips);
        $this->extractBudgetUnder($remaining, $criteria, $chips);
        $this->extractClientSpend($remaining, $criteria, $chips);
        $this->extractCountry($remaining, $criteria, $chips);

        // Exclusions ("no wordpress") must run after every fixed status/
        // action phrase above ("not sent yet", "no competition") has
        // already consumed its own wording, or this would misread "sent"
        // or "competition" as an excluded keyword.
        $this->extractExclusions($remaining, $criteria, $chips);
        $this->extractStack($remaining, $criteria, $chips);
        $this->extractLeftoverKeywords($remaining, $criteria, $chips);

        $criteria = array_filter($criteria, fn ($value) => $value !== null && $value !== [] && $value !== false);

        return [
            'criteria' => $criteria,
            'chips' => $chips,
            'understood' => $criteria !== [],
        ];
    }

    protected function extractFixedPhrases(string &$remaining, array &$criteria, array &$chips): void
    {
        $scoreCutoff = (int) ($this->settings->rules()['score_cutoff'] ?? 7);

        // Longer/more specific phrasing listed before its bare fallback
        // ("fixed price" before "fixed") so it's consumed whole first.
        $phrases = [
            'no competition' => ['proposal_max' => 5],
            'cheap to bid' => ['connects_max' => 6],
            'not sent yet' => ['status_in' => ['new', 'ready']],
            'not yet sent' => ['status_in' => ['new', 'ready']],
            'ready to bid' => ['status_in' => ['ready']],
            'reliable clients' => ['hire_rate_min' => 70.0, 'payment_verified_only' => true],
            'reliable client' => ['hire_rate_min' => 70.0, 'payment_verified_only' => true],
            'high hire rate' => ['hire_rate_min' => 70.0],
            'bid-worthy' => ['score_min' => $scoreCutoff],
            'bid worthy' => ['score_min' => $scoreCutoff],
            'boost-worthy' => ['score_min' => 9],
            'boost worthy' => ['score_min' => 9],
            'fixed price' => ['budget_type' => 'fixed'],
            'fixed-price' => ['budget_type' => 'fixed'],
            'hourly jobs' => ['budget_type' => 'hourly'],
            'last hour' => ['posted_within_minutes' => 60],
            'this week' => ['posted_within_minutes' => 10080],
            'long-term' => ['include_keywords' => ['long term']],
            'long term' => ['include_keywords' => ['long term']],
            'phase two' => ['include_keywords' => ['phase 2']],
            'phase 2' => ['include_keywords' => ['phase 2']],
            'ongoing' => ['include_keywords' => ['ongoing']],
            'bookmarked' => ['is_favorite' => true],
            'saved' => ['is_favorite' => true],
            'fixed' => ['budget_type' => 'fixed'],
            'hourly' => ['budget_type' => 'hourly'],
            'replied' => ['status_in' => ['replied']],
            'today' => ['posted_within_minutes' => 1440],
            'fresh' => ['posted_within_minutes' => 60],
            'verified' => ['payment_verified_only' => true],
        ];

        foreach ($phrases as $phrase => $values) {
            if (! $this->consume($remaining, $phrase)) {
                continue;
            }

            foreach ($values as $key => $value) {
                $this->mergeCriterion($criteria, $key, $value);
            }

            $chips[] = ['label' => $this->fixedPhraseLabel($phrase, $values), 'phrase' => $phrase];
        }
    }

    protected function fixedPhraseLabel(string $phrase, array $values): string
    {
        return match (true) {
            isset($values['proposal_max']) => 'no competition (≤5 proposals)',
            isset($values['connects_max']) => 'cheap to bid (≤6 connects)',
            isset($values['hire_rate_min']) && isset($values['payment_verified_only']) => 'reliable clients',
            isset($values['hire_rate_min']) => 'high hire rate (≥70%)',
            isset($values['score_min']) => "score {$values['score_min']}+",
            isset($values['budget_type']) => $values['budget_type'] === 'fixed' ? 'fixed price' : 'hourly',
            isset($values['posted_within_minutes']) => $phrase,
            isset($values['is_favorite']) => 'saved',
            isset($values['payment_verified_only']) => 'verified',
            default => $phrase,
        };
    }

    protected function extractProposalMax(string &$remaining, array &$criteria, array &$chips): void
    {
        if (preg_match('/(?:under|below|less than|fewer than)\s*(\d+)\s*(?:proposals|bids)/i', $remaining, $m)) {
            $this->consume($remaining, $m[0]);
            $this->mergeCriterion($criteria, 'proposal_max', (int) $m[1]);
            $chips[] = ['label' => "< {$m[1]} proposals", 'phrase' => trim($m[0])];
        }
    }

    protected function extractConnectsMax(string &$remaining, array &$criteria, array &$chips): void
    {
        if (preg_match('/(?:under|below|less than)\s*(\d+)\s*connects?/i', $remaining, $m)) {
            $this->consume($remaining, $m[0]);
            $this->mergeCriterion($criteria, 'connects_max', (int) $m[1]);
            $chips[] = ['label' => "< {$m[1]} connects", 'phrase' => trim($m[0])];
        }
    }

    protected function extractHireRate(string &$remaining, array &$criteria, array &$chips): void
    {
        if (preg_match('/hire rate\s*(?:above|over|of|at least)?\s*(\d+)\s*%?/i', $remaining, $m)) {
            $this->consume($remaining, $m[0]);
            $this->mergeCriterion($criteria, 'hire_rate_min', (float) $m[1]);
            $chips[] = ['label' => "hire rate ≥ {$m[1]}%", 'phrase' => trim($m[0])];
        }
    }

    protected function extractScore(string &$remaining, array &$criteria, array &$chips): void
    {
        if (preg_match('/score\s*(\d+)\s*(?:\+|and above|or higher|or above)?/i', $remaining, $m)) {
            $this->consume($remaining, $m[0]);
            $this->mergeCriterion($criteria, 'score_min', (int) $m[1]);
            $chips[] = ['label' => "score {$m[1]}+", 'phrase' => trim($m[0])];
        }
    }

    protected function extractBudgetBetween(string &$remaining, array &$criteria, array &$chips): void
    {
        if (preg_match('/between\s*\$?([\d,]+(?:\.\d+)?)\s*(?:and|-|to)\s*\$?([\d,]+(?:\.\d+)?)/i', $remaining, $m)) {
            $this->consume($remaining, $m[0]);
            $min = (float) str_replace(',', '', $m[1]);
            $max = (float) str_replace(',', '', $m[2]);
            $this->mergeCriterion($criteria, 'budget_min', min($min, $max));
            $this->mergeCriterion($criteria, 'budget_max', max($min, $max));
            $chips[] = [
                'label' => '$'.number_format(min($min, $max)).' – $'.number_format(max($min, $max)),
                'phrase' => trim($m[0]),
            ];
        }
    }

    protected function extractBudgetOver(string &$remaining, array &$criteria, array &$chips): void
    {
        if (preg_match('/(?:over|above|more than)\s*\$?([\d,]+(?:\.\d+)?)/i', $remaining, $m)
            || preg_match('/\$([\d,]+(?:\.\d+)?)\s*\+/', $remaining, $m)) {
            $this->consume($remaining, $m[0]);
            $value = (float) str_replace(',', '', $m[1]);
            $this->mergeCriterion($criteria, 'budget_min', $value);
            $chips[] = ['label' => 'budget > $'.number_format($value), 'phrase' => trim($m[0])];
        }
    }

    protected function extractBudgetUnder(string &$remaining, array &$criteria, array &$chips): void
    {
        if (preg_match('/(?:under|below|less than)\s*\$?([\d,]+(?:\.\d+)?)/i', $remaining, $m)) {
            $this->consume($remaining, $m[0]);
            $value = (float) str_replace(',', '', $m[1]);
            $this->mergeCriterion($criteria, 'budget_max', $value);
            $chips[] = ['label' => 'budget < $'.number_format($value), 'phrase' => trim($m[0])];
        }
    }

    protected function extractClientSpend(string &$remaining, array &$criteria, array &$chips): void
    {
        if (preg_match('/spen[t]?\s*(?:over|above|more than)?\s*\$?([\d,]+(?:\.\d+)?)\s*k\b/i', $remaining, $m)) {
            $this->consume($remaining, $m[0]);
            $value = (float) str_replace(',', '', $m[1]) * 1000;
            $this->mergeCriterion($criteria, 'min_client_spend', $value);
            $chips[] = ['label' => 'client spend > $'.number_format($value), 'phrase' => trim($m[0])];

            return;
        }

        if (preg_match('/spen[t]?\s*(?:over|above|more than)\s*\$?([\d,]+(?:\.\d+)?)/i', $remaining, $m)) {
            $this->consume($remaining, $m[0]);
            $value = (float) str_replace(',', '', $m[1]);
            $this->mergeCriterion($criteria, 'min_client_spend', $value);
            $chips[] = ['label' => 'client spend > $'.number_format($value), 'phrase' => trim($m[0])];
        }
    }

    protected function extractCountry(string &$remaining, array &$criteria, array &$chips): void
    {
        $names = array_keys($this->countrySynonyms);
        usort($names, fn ($a, $b) => strlen($b) <=> strlen($a));
        $alternation = implode('|', array_map(fn ($n) => preg_quote($n, '/'), $names));

        if (preg_match('/\bfrom (?:the )?('.$alternation.')\b(?:\s+clients?)?/i', $remaining, $m)) {
            $this->consume($remaining, $m[0]);
            $country = $this->countrySynonyms[strtolower($m[1])];
            $this->mergeCriterion($criteria, 'client_countries_include', $country);
            $chips[] = ['label' => $country.' clients', 'phrase' => trim($m[0])];

            return;
        }

        if (preg_match('/\b('.$alternation.')\s+clients?\b/i', $remaining, $m)) {
            $this->consume($remaining, $m[0]);
            $country = $this->countrySynonyms[strtolower($m[1])];
            $this->mergeCriterion($criteria, 'client_countries_include', $country);
            $chips[] = ['label' => $country.' clients', 'phrase' => trim($m[0])];
        }
    }

    protected function extractExclusions(string &$remaining, array &$criteria, array &$chips): void
    {
        if (! preg_match_all('/\b(?:no|not|exclude|excluding)\s+([a-z][a-z0-9.\-]*)/i', $remaining, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $m) {
            $word = trim($m[1]);

            if ($word === '' || in_array(strtolower($word), $this->stopwords, true)) {
                continue;
            }

            $canonical = $this->canonicalStackWord($word) ?? Str::title($word);
            $this->consume($remaining, $m[0]);
            $this->mergeCriterion($criteria, 'exclude_keywords', $canonical);
            $chips[] = ['label' => "no {$canonical}", 'phrase' => trim($m[0])];
        }
    }

    protected function extractStack(string &$remaining, array &$criteria, array &$chips): void
    {
        $variants = [];
        foreach ($this->stackSynonyms as $canonical => $phrases) {
            foreach ($phrases as $phrase) {
                $variants[$phrase] = $canonical;
            }
        }
        uksort($variants, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($variants as $phrase => $canonical) {
            if (! preg_match('/\b'.preg_quote($phrase, '/').'\b/i', $remaining, $m)) {
                continue;
            }

            $this->consume($remaining, $m[0]);
            $this->mergeCriterion($criteria, 'include_keywords', $canonical);
            $chips[] = ['label' => $canonical, 'phrase' => trim($m[0])];
        }
    }

    protected function extractLeftoverKeywords(string &$remaining, array &$criteria, array &$chips): void
    {
        $words = preg_split('/[^a-z0-9.+#]+/i', $remaining, -1, PREG_SPLIT_NO_EMPTY);
        $words = array_values(array_filter(
            $words,
            fn ($w) => strlen($w) > 1 && ! in_array(strtolower($w), $this->stopwords, true),
        ));

        if ($words === []) {
            return;
        }

        foreach ($words as $word) {
            $this->mergeCriterion($criteria, 'include_keywords', $word);
        }

        $phrase = implode(' ', $words);
        $chips[] = ['label' => $phrase, 'phrase' => $phrase];
    }

    protected function canonicalStackWord(string $word): ?string
    {
        foreach ($this->stackSynonyms as $canonical => $phrases) {
            if (in_array(strtolower($word), array_map('strtolower', $phrases), true)) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * Different criteria types AND together (LeadController::applyNlCriteria
     * runs each as its own ->where()) - this only governs what happens when
     * the SAME type is mentioned twice in one query. List-type criteria
     * union; a repeated minimum/maximum takes whichever is more
     * restrictive rather than silently overwriting the first mention.
     */
    protected function mergeCriterion(array &$criteria, string $key, mixed $value): void
    {
        if (in_array($key, ['include_keywords', 'exclude_keywords', 'client_countries_include', 'status_in'], true)) {
            $criteria[$key] = array_values(array_unique(array_merge($criteria[$key] ?? [], (array) $value)));

            return;
        }

        if (in_array($key, ['budget_min', 'score_min', 'hire_rate_min', 'min_client_spend'], true)) {
            $criteria[$key] = isset($criteria[$key]) ? max($criteria[$key], $value) : $value;

            return;
        }

        if (in_array($key, ['budget_max', 'proposal_max', 'connects_max', 'posted_within_minutes'], true)) {
            $criteria[$key] = isset($criteria[$key]) ? min($criteria[$key], $value) : $value;

            return;
        }

        $criteria[$key] = $value;
    }

    protected function consume(string &$remaining, string $phrase): bool
    {
        if (stripos($remaining, $phrase) === false) {
            return false;
        }

        $remaining = str_ireplace($phrase, ' ', $remaining);

        return true;
    }
}
