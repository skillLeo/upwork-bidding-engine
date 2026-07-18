<?php

namespace Tests\Feature\Ai;

use App\Models\AiCall;
use App\Models\Lead;
use App\Services\Ai\ProposalService;
use App\Services\Ai\ScoringService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiLayerTest extends TestCase
{
    use RefreshDatabase;

    protected SettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(SettingsService::class);
        $this->settings->set('anthropic_api_key', 'sk-ant-test');
        $this->settings->set('scoring_system_prompt', 'You score Upwork jobs. Output JSON only.');
        $this->settings->set('proposal_system_prompt', 'You write proposals.');
    }

    /**
     * @param  array<int, mixed>  $content
     * @return array<string, mixed>
     */
    protected function anthropicResponse(string $text, array $usage = []): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => $usage['input'] ?? 500,
                'output_tokens' => $usage['output'] ?? 60,
                'cache_read_input_tokens' => $usage['cache_read'] ?? 0,
                'cache_creation_input_tokens' => $usage['cache_write'] ?? 0,
            ],
        ];
    }

    public function test_scoring_parses_json_and_logs_exact_cost(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropicResponse(
                '{"score": 8, "bid": true, "boost": false, "reason": "Strong stack match"}',
                ['input' => 1000, 'output' => 50, 'cache_read' => 2000, 'cache_write' => 0],
            )),
        ]);

        $lead = Lead::factory()->create();
        $result = app(ScoringService::class)->score($lead);

        $this->assertSame(8, $result['score']);
        $this->assertTrue($result['bid']);
        $this->assertFalse($result['boost']);
        $this->assertSame('Strong stack match', $result['reason']);

        // Haiku 4.5: (1000*$1 + 2000*$1*0.1 + 50*$5) / 1M = $0.001450
        $this->assertDatabaseHas('ai_calls', [
            'purpose' => 'scoring',
            'lead_id' => $lead->id,
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5',
            'cached_tokens' => 2000,
            'success' => true,
        ]);
        $this->assertEqualsWithDelta(0.00145, AiCall::first()->cost_usd, 0.000001);

        // The system prompt must be a cache_control block with the job last.
        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['system'][0]['cache_control']['type'] ?? null) === 'ephemeral'
                && str_contains($body['messages'][0]['content'], 'TITLE:');
        });
    }

    public function test_malformed_json_retries_once_then_fails_loudly(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropicResponse('I think this job is great!')),
        ]);

        $lead = Lead::factory()->create();

        try {
            app(ScoringService::class)->score($lead);
            $this->fail('Expected a RuntimeException for malformed output.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('malformed', $e->getMessage());
        }

        Http::assertSentCount(2);
    }

    public function test_out_of_range_score_is_rejected_not_guessed(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropicResponse('{"score": 14, "bid": true}')),
        ]);

        $this->expectException(\RuntimeException::class);
        app(ScoringService::class)->score(Lead::factory()->create());
    }

    public function test_empty_prompt_fails_loudly_with_no_api_call(): void
    {
        Http::fake();
        $this->settings->set('scoring_system_prompt', '');

        try {
            app(ScoringService::class)->score(Lead::factory()->create());
            $this->fail('Expected a RuntimeException for empty prompt.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('scoring_system_prompt is empty', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_proposal_only_runs_when_score_clears_cutoff(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropicResponse('{"score": 3, "bid": false, "reason": "weak"}')),
        ]);

        $lead = Lead::factory()->create();

        $this->artisan('ai:test-score', ['lead_id' => $lead->id])
            ->expectsOutputToContain('NO proposal call')
            ->assertSuccessful();

        // Exactly one request: scoring. No proposal spend below cutoff.
        Http::assertSentCount(1);
        $this->assertSame(0, AiCall::where('purpose', 'proposal')->count());
    }

    public function test_high_score_triggers_sequential_proposal_call(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->anthropicResponse('{"score": 9, "bid": true, "boost": true, "reason": "excellent"}'))
                ->push($this->anthropicResponse('hey, saw your project — i can ship this.')),
        ]);

        $lead = Lead::factory()->create();

        $this->artisan('ai:test-score', ['lead_id' => $lead->id])
            ->expectsOutputToContain('Proposal (sequential')
            ->assertSuccessful();

        Http::assertSentCount(2);
        $this->assertSame(1, AiCall::where('purpose', 'proposal')->count());
    }

    public function test_failover_switches_to_openai_after_three_consecutive_failures(): void
    {
        $this->settings->set('openai_api_key', 'sk-test-openai');

        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529),
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => '{"score": 7, "bid": true, "reason": "ok"}']]],
                'usage' => ['prompt_tokens' => 800, 'completion_tokens' => 40, 'prompt_tokens_details' => ['cached_tokens' => 0]],
            ]),
        ]);

        $lead = Lead::factory()->create();
        $scoring = app(ScoringService::class);

        // First two failing calls raise; the third trips the failover and
        // succeeds on OpenAI within the same call.
        foreach ([1, 2] as $i) {
            try {
                $scoring->score($lead);
                $this->fail('Expected failure #'.$i);
            } catch (\Throwable) {
            }
        }

        $result = $scoring->score($lead);

        $this->assertSame(7, $result['score']);
        $this->assertSame('openai', $result['response']->provider);
        $this->assertSame('gpt-4o-mini', $result['response']->model);
        $this->assertDatabaseHas('activity_logs', ['type' => 'ai_provider_failover']);

        // While the failover window is open, calls go straight to OpenAI.
        $again = $scoring->score($lead);
        $this->assertSame('openai', $again['response']->provider);
    }

    public function test_failed_calls_are_logged_with_error(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'boom'], 500)]);
        Cache::forget(\App\Services\Ai\AiManager::FAILS_KEY);

        try {
            app(ScoringService::class)->score(Lead::factory()->create());
        } catch (\Throwable) {
        }

        $this->assertTrue(AiCall::where('success', false)->whereNotNull('error')->exists());
    }

    public function test_provider_switch_to_openai_maps_stale_claude_model_to_equivalent(): void
    {
        // The operator flips ai_provider to openai while scoring_model
        // still holds a Claude ID — the call must genuinely run on OpenAI
        // with its equivalent tier, not fail into a boomerang-failover
        // back to Anthropic.
        $this->settings->set('ai_provider', 'openai');
        $this->settings->set('openai_api_key', 'sk-test-openai');
        $this->settings->set('scoring_model', 'claude-haiku-4-5');

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => '{"score": 6, "bid": false, "reason": "ok"}']]],
                'usage' => ['prompt_tokens' => 700, 'completion_tokens' => 40],
            ]),
        ]);

        $result = app(ScoringService::class)->score(Lead::factory()->create());

        $this->assertSame('openai', $result['response']->provider);
        $this->assertSame('gpt-4o-mini', $result['response']->model);
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'api.openai.com')
            && $request->data()['model'] === 'gpt-4o-mini');
    }

    public function test_agent_score_endpoint_uses_same_rubric_and_requires_token(): void
    {
        $this->settings->set('openclaw_token', 'agent-token');

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropicResponse(
                '{"score": 8, "bid": true, "boost": false, "reason": "solid fit"}',
            )),
        ]);

        // Wrong token → 401, no AI spend.
        $this->postJson('/api/agent/score', ['brief' => 'Build a Laravel API'], ['Authorization' => 'Bearer nope'])
            ->assertStatus(401);
        Http::assertNothingSent();

        $this->postJson(
            '/api/agent/score',
            ['brief' => 'Build a Laravel API for our mobile app', 'title' => 'Laravel API', 'budget' => '$800 fixed'],
            ['Authorization' => 'Bearer agent-token'],
        )
            ->assertStatus(200)
            ->assertJsonPath('data.score', 8)
            ->assertJsonPath('data.bid', true)
            ->assertJsonPath('data.reason', 'solid fit');
    }

    public function test_proposal_service_returns_plain_text(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropicResponse("```\nhey — saw the brief, i can build this.\n```")),
        ]);

        $lead = Lead::factory()->create();
        $result = app(ProposalService::class)->write($lead, ['score' => 8, 'boost' => false, 'reason' => 'good fit']);

        $this->assertSame('hey — saw the brief, i can build this.', $result['text']);
    }
}
