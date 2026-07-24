<?php

namespace Tests\Feature\Analytics;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutcomeAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_speed_computes_median_and_p90_and_hides_below_min_sample(): void
    {
        $admin = User::factory()->admin()->create();

        // 4 leads: below the n>=5 floor - must render null, not a number.
        foreach ([10, 20, 30, 40] as $minutes) {
            Lead::factory()->sent()->create([
                'posted_at' => now()->subDays(1),
                'submitted_at' => now()->subDays(1)->addMinutes($minutes),
            ]);
        }

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/analytics')->assertOk();
        $this->assertSame(4, $res->json('data.speed.n'));
        $this->assertNull($res->json('data.speed.median_minutes'));

        // A 5th lead clears the floor.
        Lead::factory()->sent()->create([
            'posted_at' => now()->subDays(1),
            'submitted_at' => now()->subDays(1)->addMinutes(50),
        ]);

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/analytics')->assertOk();
        $this->assertSame(5, $res->json('data.speed.n'));
        $this->assertNotNull($res->json('data.speed.median_minutes'));
    }

    public function test_speed_excludes_leads_submitted_outside_the_30_day_window(): void
    {
        $admin = User::factory()->admin()->create();

        Lead::factory()->count(6)->sent()->create([
            'posted_at' => now()->subDays(40),
            'submitted_at' => now()->subDays(40),
        ]);

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/analytics')->assertOk();
        $this->assertSame(0, $res->json('data.speed.n'));
    }

    public function test_contested_reply_rate_excludes_dead_end_outcomes_but_not_null_or_replies(): void
    {
        $admin = User::factory()->admin()->create();

        Lead::factory()->count(2)->replied()->create(); // real replies
        Lead::factory()->count(3)->sent()->create(['outcome' => 'closed_no_hire']); // dead end
        Lead::factory()->count(3)->sent()->create(); // outcome null - still contested

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/analytics')->assertOk();

        // raw: 2 replied out of 8 total sent-or-beyond
        $this->assertSame(8, $res->json('data.summary.reply_rate_raw.n'));
        // contested: excludes the 3 closed_no_hire -> denominator 5 (2 replied + 3 null-outcome)
        $this->assertSame(5, $res->json('data.summary.reply_rate_contested.n'));
        $this->assertEqualsWithDelta(40.0, $res->json('data.summary.reply_rate_contested.rate'), 0.01);
    }

    public function test_dying_proposals_buckets_are_mutually_exclusive_and_correct(): void
    {
        $admin = User::factory()->admin()->create();

        Lead::factory()->sent()->create(['client_view' => 'not_viewed']);
        Lead::factory()->sent()->create(['client_view' => 'viewed']);
        Lead::factory()->sent()->create(['client_view' => 'viewed', 'outcome' => 'closed_no_hire']);
        Lead::factory()->sent()->create(['client_view' => 'shortlisted']);
        Lead::factory()->replied()->create(['client_view' => 'viewed']);

        $d = $this->actingAs($admin, 'sanctum')->getJson('/api/analytics')->assertOk()->json('data.dying_proposals');

        $this->assertSame(1, $d['not_opened']);
        $this->assertSame(2, $d['opened_no_reply']);
        $this->assertSame(1, $d['shortlisted_no_reply']);
        $this->assertSame(1, $d['replied']);
        $this->assertSame(0, $d['not_recorded']);
        $this->assertSame(5, $d['recorded']);
        $this->assertSame(5, $d['total_sent']);
    }

    /**
     * The correction that matters most: a blank client_view means "I have not
     * checked Upwork yet", NOT "the client never opened it". Counting those as
     * not_opened would make a partly-filled field read as a catastrophic title
     * problem — misleading analytics, which is worse than none.
     */
    public function test_leads_with_no_recorded_client_view_are_excluded_not_counted_as_unopened(): void
    {
        $admin = User::factory()->admin()->create();

        Lead::factory()->count(9)->sent()->create(['client_view' => null]);
        Lead::factory()->sent()->create(['client_view' => 'not_viewed']);

        $d = $this->actingAs($admin, 'sanctum')->getJson('/api/analytics')->assertOk()->json('data.dying_proposals');

        $this->assertSame(1, $d['not_opened'], 'only the one actually recorded as unopened');
        $this->assertSame(9, $d['not_recorded']);
        $this->assertSame(1, $d['recorded']);
        $this->assertSame(10, $d['total_sent']);
    }

    /**
     * A reply is hard evidence the client opened it, so it counts even when
     * the manual field was never touched — real signal is not thrown away.
     */
    public function test_a_reply_counts_as_landed_even_with_no_recorded_client_view(): void
    {
        $admin = User::factory()->admin()->create();

        Lead::factory()->replied()->create(['client_view' => null]);
        Lead::factory()->won()->create(['client_view' => null]);

        $d = $this->actingAs($admin, 'sanctum')->getJson('/api/analytics')->assertOk()->json('data.dying_proposals');

        $this->assertSame(2, $d['replied']);
        $this->assertSame(0, $d['not_recorded'], 'not_recorded only ever covers leads still awaiting a reply');
    }
}
