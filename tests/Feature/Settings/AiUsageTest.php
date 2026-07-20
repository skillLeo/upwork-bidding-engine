<?php

namespace Tests\Feature\Settings;

use App\Models\AiCall;
use App\Models\User;
use App\Services\SettingsService;
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

    public function test_provider_filter_scopes_stats_but_balances_and_by_provider_stay_full(): void
    {
        AiCall::create(['purpose' => 'scoring', 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'cost_usd' => 0.01, 'success' => true, 'created_at' => today()]);
        AiCall::create(['purpose' => 'scoring', 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'cost_usd' => 0.002, 'success' => true, 'created_at' => today()]);

        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/ai-usage?provider=openai')
            ->assertOk();

        $response->assertJsonPath('data.provider_filter', 'openai');
        // Scoped stats reflect only the openai row.
        $response->assertJsonPath('data.total_spend', 0.002);
        $response->assertJsonPath('data.total_calls', 1);

        // Balances and the "by provider" comparison are ALWAYS both,
        // regardless of the filter — that's the whole-system comparison.
        $this->assertCount(2, $response->json('data.balances'));
        $this->assertCount(2, $response->json('data.by_provider'));
    }

    public function test_balance_reflects_funded_minus_real_spend_with_burn_rate(): void
    {
        app(SettingsService::class)->set('anthropic_funded_total', 10.0);

        // 0.07 total anthropic spend, all inside the last 7 days.
        AiCall::create(['purpose' => 'scoring', 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'cost_usd' => 0.07, 'success' => true, 'created_at' => now()->subDays(2)]);

        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/ai-usage')->assertOk();

        $balances = collect($response->json('data.balances'))->keyBy('provider');

        $this->assertEquals(10.0, $balances['anthropic']['funded']);
        $this->assertEquals(0.07, $balances['anthropic']['spent']);
        $this->assertEquals(9.93, $balances['anthropic']['remaining']);
        $this->assertEquals(99.3, $balances['anthropic']['pct_remaining']);
        // 0.07 / 7 days = 0.01/day burn.
        $this->assertEquals(0.01, $balances['anthropic']['burn_rate_per_day']);
        // 9.93 remaining / 0.01 per day = 993 days.
        $this->assertEquals(993, $balances['anthropic']['days_remaining']);

        // Never funded: no percentage, no days-remaining guess, no divide by zero.
        $this->assertNull($balances['openai']['pct_remaining']);
        $this->assertNull($balances['openai']['days_remaining']);
        $this->assertEquals(0, $balances['openai']['funded']);
    }

    public function test_operator_can_update_the_funded_amount(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/settings', ['openai_funded_total' => 25.5])
            ->assertOk();

        $this->assertEquals(25.5, app(SettingsService::class)->get('openai_funded_total'));
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
