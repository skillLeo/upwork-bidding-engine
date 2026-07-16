<?php

namespace Tests\Unit;

use App\Services\NlSearchParser;
use App\Services\SettingsService;
use PHPUnit\Framework\TestCase;

class NlSearchParserTest extends TestCase
{
    protected function parser(): NlSearchParser
    {
        // No DB dependency — SettingsService is only consulted for the
        // configured score_cutoff behind "bid-worthy", so a stub return is
        // all the parser needs from it.
        $settings = $this->createMock(SettingsService::class);
        $settings->method('rules')->willReturn(['score_cutoff' => 7]);

        return new NlSearchParser($settings);
    }

    public function test_budget_over(): void
    {
        $result = $this->parser()->parse('laravel jobs over $500');

        $this->assertTrue($result['understood']);
        $this->assertSame(500.0, $result['criteria']['budget_min']);
        $this->assertContains('Laravel', $result['criteria']['include_keywords']);
    }

    public function test_budget_shorthand_plus_does_not_collide_with_score_plus(): void
    {
        $result = $this->parser()->parse('$500+ score 8+');

        $this->assertSame(500.0, $result['criteria']['budget_min']);
        $this->assertSame(8, $result['criteria']['score_min']);
    }

    public function test_budget_under(): void
    {
        $result = $this->parser()->parse('under $1000');

        $this->assertSame(1000.0, $result['criteria']['budget_max']);
    }

    public function test_budget_between(): void
    {
        $result = $this->parser()->parse('between $200 and $800');

        $this->assertSame(200.0, $result['criteria']['budget_min']);
        $this->assertSame(800.0, $result['criteria']['budget_max']);
    }

    public function test_proposals_under_does_not_leak_into_budget_under(): void
    {
        $result = $this->parser()->parse('under 10 proposals');

        $this->assertSame(10, $result['criteria']['proposal_max']);
        $this->assertArrayNotHasKey('budget_max', $result['criteria']);
    }

    public function test_no_competition_maps_to_five(): void
    {
        $result = $this->parser()->parse('no competition');

        $this->assertSame(5, $result['criteria']['proposal_max']);
    }

    public function test_connects_under_does_not_leak_into_budget_under(): void
    {
        $result = $this->parser()->parse('under 5 connects');

        $this->assertSame(5, $result['criteria']['connects_max']);
        $this->assertArrayNotHasKey('budget_max', $result['criteria']);
    }

    public function test_score_patterns(): void
    {
        $this->assertSame(8, $this->parser()->parse('score 8+')['criteria']['score_min']);
        $this->assertSame(7, $this->parser()->parse('bid-worthy')['criteria']['score_min']);
        $this->assertSame(9, $this->parser()->parse('boost-worthy')['criteria']['score_min']);
    }

    public function test_hire_rate_patterns(): void
    {
        $this->assertSame(70.0, $this->parser()->parse('high hire rate')['criteria']['hire_rate_min']);
        $this->assertSame(50.0, $this->parser()->parse('hire rate above 50%')['criteria']['hire_rate_min']);

        $reliable = $this->parser()->parse('reliable clients')['criteria'];
        $this->assertSame(70.0, $reliable['hire_rate_min']);
        $this->assertTrue($reliable['payment_verified_only']);
    }

    public function test_fixed_and_hourly(): void
    {
        $this->assertSame('fixed', $this->parser()->parse('fixed price')['criteria']['budget_type']);
        $this->assertSame('hourly', $this->parser()->parse('hourly jobs')['criteria']['budget_type']);
    }

    public function test_exclusion(): void
    {
        $result = $this->parser()->parse('laravel but no wordpress');

        $this->assertContains('Laravel', $result['criteria']['include_keywords']);
        $this->assertContains('WordPress', $result['criteria']['exclude_keywords']);
    }

    public function test_no_competition_not_read_as_an_exclusion(): void
    {
        $result = $this->parser()->parse('no competition');

        $this->assertArrayNotHasKey('exclude_keywords', $result['criteria']);
    }

    public function test_not_sent_yet_status(): void
    {
        $result = $this->parser()->parse('not sent yet');

        $this->assertSame(['new', 'ready'], $result['criteria']['status_in']);
        $this->assertArrayNotHasKey('exclude_keywords', $result['criteria']);
    }

    public function test_green_flags_become_include_keywords(): void
    {
        $result = $this->parser()->parse('long term, ongoing, phase 2 work');

        $this->assertContains('long term', $result['criteria']['include_keywords']);
        $this->assertContains('ongoing', $result['criteria']['include_keywords']);
        $this->assertContains('phase 2', $result['criteria']['include_keywords']);
    }

    public function test_country_synonyms(): void
    {
        $this->assertContains('United States', $this->parser()->parse('us clients')['criteria']['client_countries_include']);
        $this->assertContains('New Zealand', $this->parser()->parse('clients from new zealand')['criteria']['client_countries_include']);
    }

    public function test_verified_and_freshness(): void
    {
        $result = $this->parser()->parse('verified, fresh');

        $this->assertTrue($result['criteria']['payment_verified_only']);
        $this->assertSame(60, $result['criteria']['posted_within_minutes']);
    }

    public function test_saved_flag(): void
    {
        $this->assertTrue($this->parser()->parse('saved leads')['criteria']['is_favorite']);
    }

    public function test_and_combination_across_categories(): void
    {
        $result = $this->parser()->parse('laravel over $500 verified under 10 proposals');

        $this->assertContains('Laravel', $result['criteria']['include_keywords']);
        $this->assertSame(500.0, $result['criteria']['budget_min']);
        $this->assertTrue($result['criteria']['payment_verified_only']);
        $this->assertSame(10, $result['criteria']['proposal_max']);
    }

    public function test_voice_mishearing_synonyms(): void
    {
        $cases = [
            'level jobs' => 'Laravel',
            'next js work' => 'Next.js',
            'oh do project' => 'Odoo',
            'murn stack' => 'MERN',
            'fast api backend' => 'FastAPI',
            'post grass sql' => 'PostgreSQL',
            'node js developer' => 'Node',
            'reactnative app' => 'React Native',
        ];

        foreach ($cases as $spoken => $canonical) {
            $result = $this->parser()->parse($spoken);
            $this->assertContains(
                $canonical,
                $result['criteria']['include_keywords'] ?? [],
                "Expected \"{$spoken}\" to resolve to {$canonical}",
            );
        }
    }

    public function test_leftover_free_text_becomes_keyword_search(): void
    {
        $result = $this->parser()->parse('dashboard rebuild for logistics startup');

        $this->assertTrue($result['understood']);
        $this->assertNotEmpty($result['criteria']['include_keywords']);
    }

    public function test_empty_query_is_not_understood(): void
    {
        $result = $this->parser()->parse('   ');

        $this->assertFalse($result['understood']);
        $this->assertSame([], $result['criteria']);
    }

    public function test_chips_carry_the_matched_phrase_for_removal(): void
    {
        $result = $this->parser()->parse('laravel over $500');

        $labels = array_column($result['chips'], 'label');
        $this->assertContains('Laravel', $labels);
        $this->assertContains('budget > $500', $labels);

        foreach ($result['chips'] as $chip) {
            $this->assertNotNull($chip['phrase']);
        }
    }
}
