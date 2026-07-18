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
     * inside the word bounds, contains "Done =", ends with "Hassam".
     */
    protected const CLEAN_TEXT = "The tricky part here is the offline sync.\nI built PatrolTick, which syncs offline check-ins on reconnect.\nPlan: audit the sync path, fix the queue, test on a real device. Done = check-ins land reliably.\nIs your data in one system, or several?\nHassam";

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(SettingsService::class);
        $this->settings->set('anthropic_api_key', 'sk-ant-test');
        $this->settings->set('proposal_system_prompt', 'You write proposals under the full rulebook.');
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

    public function test_model_review_rejection_triggers_revision(): void
    {
        $this->configureLint();

        $better = str_replace('offline sync', 'checkout drop-off', self::CLEAN_TEXT);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->anthropicResponse(self::CLEAN_TEXT))
                ->push($this->anthropicResponse('{"pass": false, "violations": ["Line 1 could be pasted into any job — not A-pile"]}'))
                ->push($this->anthropicResponse($better))
                ->push($this->anthropicResponse('{"pass": true, "violations": []}')),
        ]);

        $result = $this->write(Lead::factory()->create());

        $this->assertSame($better, $result['text']);
        $this->assertTrue($result['clean']);
        $this->assertSame(1, $result['revisions']);
        Http::assertSentCount(4);
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

    public function test_returns_best_effort_after_revision_cap_with_warning(): void
    {
        $this->configureLint();

        // Every call (draft + both revisions) returns the same rule-breaking
        // text — the gate must give up after MAX_REVISIONS, return the text
        // anyway (visible beats vanished), and flag it.
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropicResponse('Still full of leverage — sadly.')),
        ]);

        $result = $this->write(Lead::factory()->create());

        $this->assertSame('Still full of leverage — sadly.', $result['text']);
        $this->assertFalse($result['clean']);
        $this->assertSame(ProposalService::MAX_REVISIONS, $result['revisions']);
        Http::assertSentCount(1 + ProposalService::MAX_REVISIONS);
        $this->assertDatabaseHas('activity_logs', ['type' => 'proposal_quality_warning']);
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

        $this->assertStringContainsString('em/en dash', $text);
        $this->assertStringContainsString('"leverage"', $text);
        $this->assertStringContainsString('"robust"', $text);
        $this->assertStringContainsString('"Kindly"', $text);
        $this->assertStringContainsString('Too short', $text);
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

    public function test_sync_prompts_command_loads_repo_rules_into_settings(): void
    {
        $this->artisan('ai:sync-prompts', ['--only' => 'proposal'])->assertSuccessful();

        $prompt = (string) $this->settings->get('proposal_system_prompt');

        $this->assertGreaterThan(60000, mb_strlen($prompt));
        $this->assertStringContainsString('SKILL.md v2', $prompt);
        $this->assertStringContainsString('slippery slide', $prompt);
        $this->assertStringContainsString('OUTPUT OVERRIDE', $prompt);
    }
}
