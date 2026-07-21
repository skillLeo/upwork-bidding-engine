<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrioritySortTest extends TestCase
{
    use RefreshDatabase;

    public function test_priority_sort_ranks_a_fresh_mid_score_above_a_stale_high_score(): void
    {
        $bidder = User::factory()->bidder()->create();

        $staleHigh = Lead::factory()->ready(9)->create([
            'title' => 'Stale nine',
            'posted_at' => now()->subDays(3),
        ]);
        $freshMid = Lead::factory()->ready(7)->create([
            'title' => 'Fresh seven',
            'posted_at' => now()->subMinutes(20),
        ]);

        $ids = collect(
            $this->actingAs($bidder, 'sanctum')
                ->getJson('/api/leads?sort=-priority')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        // At the default decay, the 3-day-old 9 has decayed below the 20-min 7.
        $this->assertLessThan(
            array_search($staleHigh->id, $ids, true),
            array_search($freshMid->id, $ids, true),
            'Fresh 7 should rank above stale 9 under priority sort.',
        );
    }

    public function test_a_recent_high_score_still_outranks_a_fresh_mid_score(): void
    {
        $bidder = User::factory()->bidder()->create();

        $recentHigh = Lead::factory()->ready(9)->create([
            'posted_at' => now()->subHours(2), // barely decayed
        ]);
        $freshMid = Lead::factory()->ready(7)->create([
            'posted_at' => now()->subMinutes(5),
        ]);

        $ids = collect(
            $this->actingAs($bidder, 'sanctum')
                ->getJson('/api/leads?sort=-priority')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertLessThan(
            array_search($freshMid->id, $ids, true),
            array_search($recentHigh->id, $ids, true),
            'A 2-hour-old 9 should still beat a fresh 7.',
        );
    }

    public function test_decay_rate_is_read_from_settings(): void
    {
        // Zero decay makes priority == score, so the high score wins even when
        // stale - proves the rate is live-tunable, not hardcoded.
        app(SettingsService::class)->set('priority_decay_rate', 0);
        $bidder = User::factory()->bidder()->create();

        $staleHigh = Lead::factory()->ready(9)->create(['posted_at' => now()->subDays(10)]);
        $freshMid = Lead::factory()->ready(7)->create(['posted_at' => now()->subMinutes(1)]);

        $ids = collect(
            $this->actingAs($bidder, 'sanctum')
                ->getJson('/api/leads?sort=-priority')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertLessThan(
            array_search($freshMid->id, $ids, true),
            array_search($staleHigh->id, $ids, true),
            'With decay 0, the stale 9 should win (priority == score).',
        );
    }
}
