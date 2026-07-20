<?php

namespace Tests\Feature\Ai;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\Services\Ai\AnthropicProvider;
use App\Services\Ai\OpenAiProvider;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * A 429 is a self-clearing token-bucket limit, not a real failure — verified
 * live against production (this org's gpt-4o tier caps at 30k TPM and a
 * queued proposal run hit it mid-burst). Providers must ride it out with a
 * retry instead of surfacing it as a crash on the first bounce.
 */
class RateLimitRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_openai_provider_retries_a_429_and_succeeds(): void
    {
        app(SettingsService::class)->set('openai_api_key', 'sk-test');

        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Rate limit reached']], 429)
            ->push([
                'choices' => [['message' => ['content' => 'Hello.']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2],
            ], 200)]);

        $response = app(OpenAiProvider::class)->complete('system', 'user', 'gpt-4o', 100);

        $this->assertSame('Hello.', $response->text);
        Http::assertSentCount(2);
    }

    public function test_openai_provider_gives_up_after_exhausting_retries(): void
    {
        app(SettingsService::class)->set('openai_api_key', 'sk-test');

        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Rate limit reached']], 429)]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        try {
            app(OpenAiProvider::class)->complete('system', 'user', 'gpt-4o', 100);
        } finally {
            // Initial attempt + 3 retries, never more.
            Http::assertSentCount(4);
        }
    }

    public function test_anthropic_provider_retries_a_429_and_succeeds(): void
    {
        app(SettingsService::class)->set('anthropic_api_key', 'sk-ant-test');

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Rate limit reached']], 429)
            ->push([
                'content' => [['type' => 'text', 'text' => 'Hello.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
            ], 200)]);

        $response = app(AnthropicProvider::class)->complete('system', 'user', 'claude-haiku-4-5', 100);

        $this->assertSame('Hello.', $response->text);
        Http::assertSentCount(2);
    }

    public function test_a_non_rate_limit_error_is_not_retried(): void
    {
        app(SettingsService::class)->set('openai_api_key', 'sk-test');

        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'invalid api key']], 401)]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        try {
            app(OpenAiProvider::class)->complete('system', 'user', 'gpt-4o', 100);
        } finally {
            Http::assertSentCount(1);
        }
    }

    /**
     * The dashboard Rewrite button hitting exactly this — a live queue
     * failure log showed ScoreLeadJob's proposal call dying on a 429 with
     * no friendly message, which is what surfaced as a bare "Server Error"
     * on click. Once retries are truly exhausted, the response must be a
     * clear, actionable message, never an uncaught crash.
     */
    public function test_regenerate_proposal_returns_a_friendly_503_once_retries_are_exhausted(): void
    {
        Sleep::fake();
        $admin = User::factory()->admin()->create();
        app(SettingsService::class)->setMany([
            'openai_api_key' => 'sk-test',
            'ai_provider' => 'openai',
            'scoring_system_prompt' => 'RUBRIC',
            'proposal_skill' => 'SKILL',
        ]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Rate limit reached']], 429)]);

        $lead = Lead::factory()->create(['status' => LeadStatus::Ready, 'score' => 8]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/leads/{$lead->id}/regenerate-proposal");

        $response->assertStatus(503)->assertJsonPath('message', 'The AI provider is rate-limited right now. Wait a minute and try again.');
    }
}
