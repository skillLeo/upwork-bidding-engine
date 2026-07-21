<?php

namespace Tests\Feature\Vollna;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The "Sync now" button runs the additive poll INLINE and returns a real
 * count (or the real failure reason) - it no longer silently queues a broken
 * mirror job.
 */
class SyncButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->setMany([
            'vollna_api_token' => 'test-api-token',
            'vollna_filter_id' => '40694',
            'anthropic_api_key' => 'sk-ant-test',
            'scoring_system_prompt' => 'THE RUBRIC',
            'proposal_skill' => 'THE GUIDE',
            'proposal_quality_gate' => false,
            'min_budget' => 0,
        ]);
    }

    /** @return array<string, mixed> */
    private function project(string $pid, string $title): array
    {
        return [
            'title' => $title,
            'description' => 'A real brief with enough detail to score properly.',
            'url' => "https://www.vollna.com/go?pid={$pid}&url=https%3A%2F%2Fwww.upwork.com%2Fjobs%2F~01",
            'publishedAt' => now()->subMinutes(5)->toIso8601String(),
            'skills' => ['Laravel', 'PHP'],
            'budget' => ['type' => 'FIXED', 'amount' => '500'],
            'connectsRequired' => 6,
            'clientDetails' => ['country' => 'United States', 'totalSpent' => '3.3k', 'hireRate' => 1, 'paymentMethodVerified' => true],
        ];
    }

    /** @return array<string, mixed> */
    private function anthropic(): array
    {
        return [
            'content' => [['type' => 'text', 'text' => '{"score":8,"bid":true,"reason":"ok"}']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
        ];
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_sync_runs_the_poll_synchronously_and_returns_a_count(): void
    {
        Http::fake([
            'api.vollna.com/*' => Http::response(['data' => [$this->project('111', 'Laravel API Developer')]]),
            'api.anthropic.com/*' => Http::response($this->anthropic()),
            '*' => Http::response([], 200),
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/leads/sync-vollna')
            ->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.message', '1 new lead imported.');

        $this->assertDatabaseHas('leads', ['external_id' => 'vollna_pid_111']);

        // Crucially: NO `limit` param (Vollna rejects it with 400).
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'api.vollna.com')
            && ! str_contains((string) $request->url(), 'limit'));
    }

    public function test_sync_reports_up_to_date_when_nothing_new(): void
    {
        Http::fake([
            'api.vollna.com/*' => Http::response(['data' => []]),
            '*' => Http::response([], 200),
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/leads/sync-vollna')
            ->assertOk()
            ->assertJsonPath('data.imported', 0);
    }

    public function test_sync_surfaces_a_rate_limit_error_instead_of_failing_silently(): void
    {
        Http::fake([
            'api.vollna.com/*' => Http::response('too many requests', 429),
            '*' => Http::response([], 200),
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/leads/sync-vollna')
            ->assertStatus(503);

        $this->assertStringContainsStringIgnoringCase('rate', (string) $response->json('message'));
    }
}
