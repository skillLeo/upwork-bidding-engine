<?php

namespace Tests\Feature\Settings;

use App\Models\AiCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_bidder_cannot_see_spend(): void
    {
        $this->getJson('/api/ai-usage')->assertStatus(401);

        $bidder = User::factory()->bidder()->create();
        $this->actingAs($bidder, 'sanctum')->getJson('/api/ai-usage')->assertStatus(403);
    }

    public function test_admin_sees_real_spend_computed_from_the_ledger(): void
    {
        AiCall::create(['purpose' => 'scoring', 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'cost_usd' => 0.002, 'success' => true, 'created_at' => today()]);
        AiCall::create(['purpose' => 'scoring', 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'cost_usd' => 0.003, 'success' => true, 'created_at' => today()]);
        AiCall::create(['purpose' => 'proposal', 'provider' => 'openai', 'model' => 'gpt-4o', 'cost_usd' => 0.05, 'success' => true, 'created_at' => today()]);
        AiCall::create(['purpose' => 'proposal_review', 'provider' => 'openai', 'model' => 'gpt-4o', 'cost_usd' => 0.01, 'success' => true, 'created_at' => today()]);
        AiCall::create(['purpose' => 'scoring', 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'cost_usd' => 0, 'success' => false, 'error' => 'boom', 'created_at' => today()]);
        // Outside today, but inside the 30-day window. created_at isn't
        // fillable (the model's own `creating` hook owns it), so it has
        // to be set after the fact or the row silently lands on "now".
        tap(
            AiCall::create(['purpose' => 'scoring', 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'cost_usd' => 0.004, 'success' => true]),
            fn ($call) => $call->forceFill(['created_at' => now()->subDays(5)])->save(),
        );

        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/ai-usage')->assertOk();

        $response->assertJsonPath('data.total_spend', 0.069);
        $response->assertJsonPath('data.spend_today', 0.065);
        $response->assertJsonPath('data.total_calls', 6);
        // 5 of 6 succeeded.
        $response->assertJsonPath('data.success_rate', 83.3);
        // (0.002 + 0.003) / 2 successful scoring calls today, ignoring the
        // failed one and the 5-days-ago one is still averaged in (avg is
        // all-time, not scoped to today).
        $response->assertJsonPath('data.avg_cost_per_scored_lead', 0.003);
        // One proposal run (1 draft call) carried 0.05 + 0.01 in follow-ups.
        $response->assertJsonPath('data.avg_cost_per_proposal', 0.06);

        $byProvider = collect($response->json('data.by_provider'))->keyBy('provider');
        $this->assertEquals(4, $byProvider['anthropic']['calls']);
        $this->assertEqualsWithDelta(0.009, $byProvider['anthropic']['cost'], 0.0001);
        $this->assertEquals(2, $byProvider['openai']['calls']);
        $this->assertEqualsWithDelta(0.06, $byProvider['openai']['cost'], 0.0001);

        $daily = collect($response->json('data.daily'));
        $this->assertCount(30, $daily);
        $this->assertEquals(0.065, $daily->firstWhere('date', today()->toDateString())['cost']);
        // A day with no calls is present and zero, not skipped.
        $this->assertEquals(0.0, $daily->firstWhere('date', now()->subDays(1)->toDateString())['cost']);
    }

    public function test_empty_ledger_returns_nulls_not_errors(): void
    {
        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/ai-usage')->assertOk();

        $response->assertJsonPath('data.total_spend', 0);
        $response->assertJsonPath('data.total_calls', 0);
        $response->assertJsonPath('data.success_rate', null);
        $response->assertJsonPath('data.avg_cost_per_scored_lead', null);
        $response->assertJsonPath('data.avg_cost_per_proposal', null);
        $this->assertCount(30, $response->json('data.daily'));
    }
}
