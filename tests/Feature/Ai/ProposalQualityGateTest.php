<?php

namespace Tests\Feature\Ai;

use App\Models\AiCall;
use App\Models\Lead;
use App\Services\Ai\ProposalLinter;
use App\Services\Ai\ProposalService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProposalQualityGateTest extends TestCase
{
    use RefreshDatabase;

    protected SettingsService $settings;

    /**
     * Passes the tiny lint config from configureLint(): no banned phrases,
     * inside the word bounds, contains "Done =", ends with "Hassam", has a
     * short sentence, repeats nothing.
     */
    protected const CLEAN_TEXT = "The tricky part here is the offline sync.\nI built PatrolTick, which syncs offline check-ins on reconnect.\nPlan: audit the sync path, fix the queue, test on a real device. Done = check-ins land reliably.\nIs your data in one system, or several?\nHassam";

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(SettingsService::class);
        $this->settings->set('anthropic_api_key', 'sk-ant-test');
        $this->settings->set('proposal_skill', 'You write proposals under the operative skill.');
    }

    /**
     * Small, deterministic lint config so fixtures stay short — the gate
     * mechanism is what's under test, not the seeded production lists
     * (those are covered by test_default_lint_config_catches_ai_tells).
     */
    protected function configureLint(): void
    {
        $this->settings->setMany([
            'proposal_quality_gate' => true,
            'proposal_min_words' => 3,
            'proposal_max_words' => 500,
            'proposal_signature' => 'Hassam',
            'proposal_required_phrases' => ['Done ='],
            'proposal_banned_phrases' => ['—', 'leverage'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function anthropicResponse(string $text): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 500,
                'output_tokens' => 60,
                'cache_read_input_tokens' => 0,
                'cache_creation_input_tokens' => 0,
            ],
        ];
    }

    protected function write(Lead $lead): array
    {
        return app(ProposalService::class)->write($lead, ['score' => 8, 'boost' => false, 'reason' => 'good fit']);
    }

    public function test_draft_with_lint_violations_is_revised_until_clean(): void
    {
        $this->configureLint();

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->anthropicResponse('I will leverage my skills — trust me.'))
                ->push($this->anthropicResponse(self::CLEAN_TEXT))
                ->push($this->anthropicResponse('{"pass": true, "violations": []}')),
        ]);

        $result = $this->write(Lead::factory()->create());

        $this->assertSame(self::CLEAN_TEXT, $result['text']);
        $this->assertTrue($result['clean']);
        $this->assertSame('a', $result['shipped_rule']);
        $this->assertSame(1, $result['revisions']);

        // Draft, revision, then ONE paid review on the lint-clean rewrite —
        // the lint-rejected draft never wastes a review call.
        Http::assertSentCount(3);
        $this->assertSame(1, AiCall::where('purpose', 'proposal')->count());
        $this->assertSame(1, AiCall::where('purpose', 'proposal_revision')->count());
        $this->assertSame(1, AiCall::where('purpose', 'proposal_review')->count());

        // The revision request must spell out the exact violations.
        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains((string) ($body['messages'][0]['content'] ?? ''), 'banned phrase "leverage"');
        });
    }

    public function test_lint_dirty_version_never_ships_over_lint_clean_history(): void
    {
        // THE bug this ladder replaces: revision 1 was lint-clean, revision
        // 2 was lint-dirty, and the old loop shipped revision 2. Here the
        // draft is clean but review-rejected, and both revisions come back
        // dirty — the CLEAN draft must ship, with the review violations on
        // the badge, never the dirty newest text.
        $this->configureLint();

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->anthropicResponse(self::CLEAN_TEXT))
                ->push($this->anthropicResponse('{"pass": false, "violations": [{"rule": "TRICOLON", "quote": "audit, fix, test", "reason": "Three parallel items — a banned rhythm."}]}'))
                ->push($this->anthropicResponse('Dirty rewrite — with a dash.'))
                ->push($this->anthropicResponse('Still dirty — again.')),
        ]);

        $result = $this->write(Lead::factory()->create());

        $this->assertSame(self::CLEAN_TEXT, $result['text']);
        $this->assertSame('b', $result['shipped_rule']);
        $this->assertFalse($result['clean']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('TRICOLON', $result['warnings'][0]);

        // Reviewer prose is scrubbed before it reaches the writer or the
        // badge — the em dash in the reason must not survive.
        $this->assertStringNotContainsString('—', implode(' ', $result['warnings']));

        // Dirty versions never earn a review call: draft, review, 2 revisions.
        Http::assertSentCount(4);
        $this->assertDatabaseHas('activity_logs', ['type' => 'proposal_quality_warning']);
    }

    public function test_surgical_fix_ships_when_it_cleans_the_text(): void
    {
        $this->configureLint();

        // Every generation is lint-dirty, so after the revision budget the
        // ladder runs ONE surgical fix — which succeeds and ships.
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->anthropicResponse('Bad — one.'))
                ->push($this->anthropicResponse('Bad — two.'))
                ->push($this->anthropicResponse('Bad — three.'))
                ->push($this->anthropicResponse(self::CLEAN_TEXT)),
        ]);

        $result = $this->write(Lead::factory()->create());

        $this->assertSame(self::CLEAN_TEXT, $result['text']);
        $this->assertSame('c', $result['shipped_rule']);
        $this->assertSame([], $result['warnings']);
        Http::assertSentCount(4);
        $this->assertSame(1, AiCall::where('purpose', 'proposal_surgical_fix')->count());
    }

    public function test_returns_best_effort_after_everything_fails_with_visible_warnings(): void
    {
        $this->configureLint();

        // Every call (draft, both revisions, the surgical fix) returns the
        // same rule-breaking text — the gate must give up, return the text
        // anyway (visible beats vanished), and put the violations where
        // the operator reads proposals.
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropicResponse('Still full of leverage — sadly.')),
        ]);

        $result = $this->write(Lead::factory()->create());

        $this->assertSame('Still full of leverage — sadly.', $result['text']);
        $this->assertSame('d', $result['shipped_rule']);
        $this->assertFalse($result['clean']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertSame(ProposalService::MAX_REVISIONS, $result['revisions']);
        Http::assertSentCount(1 + ProposalService::MAX_REVISIONS + 1);
        $this->assertDatabaseHas('activity_logs', ['type' => 'proposal_quality_warning']);
    }

    public function test_unparseable_review_never_blocks_the_pipeline(): void
    {
        $this->configureLint();

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->anthropicResponse(self::CLEAN_TEXT))
                ->push($this->anthropicResponse('Sure! The draft looks good to me.')),
        ]);

        $result = $this->write(Lead::factory()->create());

        $this->assertSame(self::CLEAN_TEXT, $result['text']);
        $this->assertTrue($result['clean']);
        Http::assertSentCount(2);
        $this->assertDatabaseHas('activity_logs', ['type' => 'proposal_review_unparseable']);
    }

    public function test_gate_disabled_returns_first_draft_with_single_call(): void
    {
        $this->configureLint();
        $this->settings->set('proposal_quality_gate', false);

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropicResponse('Anything goes — leverage away.')),
        ]);

        $result = $this->write(Lead::factory()->create());

        $this->assertSame('Anything goes — leverage away.', $result['text']);
        Http::assertSentCount(1);
    }

    public function test_default_lint_config_catches_ai_tells(): void
    {
        // No configureLint() here — this exercises the seeded production
        // defaults (SKILL.md v2 lists, 110-180 words, "Done =", "Hassam").
        $violations = app(ProposalLinter::class)->check(
            'Kindly note I will leverage my robust skills — I am the best fit. Reach me at dev@example.com or on WhatsApp.'
        );

        $text = implode("\n", $violations);

        $this->assertStringContainsString('em dash', $text);
        $this->assertStringContainsString('"leverage"', $text);
        $this->assertStringContainsString('"robust"', $text);
        $this->assertStringContainsString('"Kindly"', $text);
        $this->assertStringContainsString('too short', $text);
        $this->assertStringContainsString('Done =', $text);
        $this->assertStringContainsString('"Hassam"', $text);
        $this->assertStringContainsString('email address', $text);
        $this->assertStringContainsString('whatsapp', strtolower($text));

        // Word-boundary sanity: "art" in the list must never flag "part".
        $this->settings->set('proposal_banned_phrases', ['art']);
        $this->assertSame(
            [],
            array_filter(
                app(ProposalLinter::class)->check(str_replace('offline sync', 'a part of the app', self::CLEAN_TEXT)),
                fn (string $v) => str_contains($v, '"art"'),
            ),
        );
    }

    public function test_structural_checks_duplication_rhythm_markdown_and_stray_punctuation(): void
    {
        $this->settings->setMany([
            'proposal_min_words' => 1,
            'proposal_max_words' => 5000,
            'proposal_signature' => '',
            'proposal_required_phrases' => [],
            'proposal_banned_phrases' => [],
        ]);

        $linter = app(ProposalLinter::class);

        // Same sentence twice in the letter.
        $dup = $linter->check("I ship the fix fast every single time. Then we test.\nI ship the fix fast every single time.");
        $this->assertStringContainsString('Repeats itself', implode("\n", $dup));

        // An 8-word run shared between numbered answers and the letter.
        $shared = $linter->check(
            "1. I would map the reserved stock buffers per channel first thing.\n\nMy plan: map the reserved stock buffers per channel first, then sync. Short line."
        );
        $this->assertStringContainsString('Repeats itself', implode("\n", $shared));

        // No sentence of 8 words or fewer anywhere in the letter.
        $rhythm = $linter->check(
            'This letter contains only very long sentences that ramble on and on without any variation whatsoever in their length or their rhythm. Another equally long sentence follows the first one to make completely sure that no short sentence appears anywhere in this text.'
        );
        $this->assertStringContainsString('No short sentence', implode("\n", $rhythm));

        // Markdown artifacts, each named.
        $markdown = implode("\n", $linter->check("Intro line here. Short one.\n---\n**Bold claim** with `code` and\n## A heading"));
        $this->assertStringContainsString('separator', $markdown);
        $this->assertStringContainsString('bold', $markdown);
        $this->assertStringContainsString('heading', $markdown);
        $this->assertStringContainsString('backticks', $markdown);

        // Stray punctuation at the very start.
        $stray = $linter->check('- dashed start of a letter. Short one.');
        $this->assertStringContainsString('stray punctuation', implode("\n", $stray));
    }

    public function test_word_bounds_apply_to_letter_body_only(): void
    {
        $this->settings->setMany([
            'proposal_min_words' => 3,
            'proposal_max_words' => 25,
            'proposal_signature' => 'Hassam',
            'proposal_required_phrases' => [],
            'proposal_banned_phrases' => [],
        ]);

        // Two long numbered screening answers push the TOTAL far past 25
        // words, but the letter body itself stays inside the bounds — so
        // no word-count violation may fire.
        $text = "1. Yes, I have shipped multi-tenant billing systems in Laravel with per-tenant data isolation and invoice generation handled end to end.\n\n"
            ."2. My availability is thirty hours weekly in your timezone overlap, starting immediately after scope confirmation on the first milestone.\n\n"
            ."Your billing edge cases are the real work here. Done right, this stays small.\nHassam";

        $violations = app(ProposalLinter::class)->check($text);

        $this->assertSame(
            [],
            array_filter($violations, fn (string $v) => str_contains($v, 'too long') || str_contains($v, 'too short')),
            'Word bounds must ignore the screening answers: '.implode(' | ', $violations),
        );
    }

    public function test_linter_messages_contain_no_dash_characters(): void
    {
        // Violation text is fed back to the writing model verbatim, and
        // models imitate what they read — so the feedback itself must
        // never demonstrate the banned style.
        $violations = app(ProposalLinter::class)->check(
            "— Kindly leverage this robust — thing.\n---\n**bold** `tick` -- and no signature"
        );

        $this->assertNotEmpty($violations);

        foreach ($violations as $violation) {
            $this->assertStringNotContainsString('—', $violation, $violation);
            $this->assertStringNotContainsString('–', $violation, $violation);
        }
    }

    public function test_sync_prompts_command_loads_split_rules_into_settings(): void
    {
        $this->artisan('ai:sync-prompts')->assertSuccessful();

        $skill = (string) $this->settings->get('proposal_skill');
        $reference = (string) $this->settings->get('proposal_reference');

        // The skill stays lean and operative; the teaching document lands
        // in the never-sent reference field, not the skill.
        $this->assertStringContainsString('SKILL.md v2', $skill);
        $this->assertLessThan(15000, mb_strlen($skill));
        $this->assertGreaterThan(60000, mb_strlen($reference));
        $this->assertStringContainsString('Sugarman', $reference);
        $this->assertStringNotContainsString('Sugarman', $skill);
    }

    public function test_system_prompt_is_skill_plus_facts_plus_format_spec_and_never_the_reference(): void
    {
        $this->settings->set('proposal_skill', 'SKILL RULES v2 MARKER');
        $this->settings->set('proposal_reference', 'TEACHING DOC MARKER — must never be sent');

        $system = app(ProposalService::class)->systemPrompt();

        $this->assertStringContainsString('SKILL RULES v2 MARKER', $system);
        $this->assertStringContainsString('PROJECT FACTS', $system);
        // Seeded default fact sheet rides along untouched.
        $this->assertStringContainsString('Magento is not on this sheet', $system);
        $this->assertStringContainsString('OUTPUT FORMAT (strict)', $system);
        $this->assertStringNotContainsString('TEACHING DOC MARKER', $system);

        // Order: skill first, facts second, format spec last — the static
        // block must be byte-identical between calls for caching, and the
        // brief always arrives after it in the user turn.
        $this->assertTrue(
            strpos($system, 'SKILL RULES v2 MARKER') < strpos($system, 'PROJECT FACTS')
            && strpos($system, 'PROJECT FACTS') < strpos($system, 'OUTPUT FORMAT (strict)'),
        );

        // Empty skill fails loudly — no proposal may run ruleless.
        $this->settings->set('proposal_skill', '');
        $this->expectException(\RuntimeException::class);
        app(ProposalService::class)->systemPrompt();
    }
}
