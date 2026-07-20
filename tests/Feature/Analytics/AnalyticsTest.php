<?php

namespace Tests\Feature\Analytics;

use App\Models\Lead;
use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_analytics(): void
    {
        $this->getJson('/api/analytics')->assertStatus(401);
    }

    public function test_score_calibration_computes_correct_per_score_counts_and_rates(): void
    {
        $admin = User::factory()->admin()->create();

        // Score 9: 4 sent, 2 replied (1 of which won) -> reply 50%, win 25%.
        Lead::factory()->count(2)->sent(9)->create();
        Lead::factory()->count(1)->replied(9)->create();
        Lead::factory()->count(1)->won(9)->create();

        // Score 6: 2 sent, 0 replies -> reply 0%, win 0%.
        Lead::factory()->count(2)->sent(6)->create();

        // Score 8 was scored but never sent - must not appear at all.
        Lead::factory()->count(3)->ready(8)->create();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/analytics')->assertOk();

        $rows = collect($response->json('data.score_calibration.rows'))->keyBy('score');

        $this->assertSame(AnalyticsService::LOW_CONFIDENCE_THRESHOLD, $response->json('data.score_calibration.low_confidence_threshold'));

        $this->assertFalse($rows->has(8), 'a score with zero sent leads must be omitted, not zero-filled');

        $nine = $rows->get(9);
        $this->assertSame(4, $nine['sent_count']);
        $this->assertSame(2, $nine['reply_count']);
        $this->assertSame(1, $nine['win_count']);
        $this->assertEquals(50.0, $nine['reply_rate']);
        $this->assertEquals(25.0, $nine['win_rate']);

        $six = $rows->get(6);
        $this->assertSame(2, $six['sent_count']);
        $this->assertSame(0, $six['reply_count']);
        $this->assertEquals(0.0, $six['reply_rate']);
        $this->assertEquals(0.0, $six['win_rate']);
    }

    public function test_score_calibration_omits_scores_with_no_sent_leads_entirely(): void
    {
        $admin = User::factory()->admin()->create();

        Lead::factory()->count(5)->ready(10)->create();
        Lead::factory()->count(5)->archived()->create();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/analytics')->assertOk();

        $this->assertSame([], $response->json('data.score_calibration.rows'));
    }
}
