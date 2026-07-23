<?php

namespace Tests\Feature\Diagnostics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthPingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_reachable_without_a_token(): void
    {
        Cache::forever('cron:last_tick', now()->toIso8601String());

        $this->getJson('/api/health/ping')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_a_fresh_tick_reports_ok_with_the_age_in_seconds(): void
    {
        Cache::forever('cron:last_tick', now()->subSeconds(30)->toIso8601String());

        $res = $this->getJson('/api/health/ping')->assertOk();

        $this->assertTrue($res->json('ok'));
        $this->assertEqualsWithDelta(30, $res->json('cron_last_tick_seconds_ago'), 2);
    }

    public function test_a_stale_tick_reports_not_ok_but_still_http_200(): void
    {
        // 181s — one second past the threshold. The monitor reads the body,
        // so the status code must stay 200 even in the failure state.
        Cache::forever('cron:last_tick', now()->subSeconds(181)->toIso8601String());

        $this->getJson('/api/health/ping')
            ->assertOk()
            ->assertJson(['ok' => false]);
    }

    public function test_a_never_ticked_scheduler_reports_null_rather_than_zero(): void
    {
        Cache::forget('cron:last_tick');

        $res = $this->getJson('/api/health/ping')->assertOk();

        $this->assertFalse($res->json('ok'));
        $this->assertNull($res->json('cron_last_tick_seconds_ago'));
    }

    public function test_it_reports_queue_depth_and_failed_jobs(): void
    {
        Cache::forever('cron:last_tick', now()->toIso8601String());

        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database',
            'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
        ]);

        $this->getJson('/api/health/ping')
            ->assertOk()
            ->assertJson(['queue_depth' => 1, 'failed_jobs' => 1]);
    }

    /**
     * The route is unauthenticated, so the blast radius of what it returns
     * matters more than usual. Lock the response shape down: exactly the
     * four keys a monitor needs, and nothing that describes the pipeline.
     */
    public function test_it_leaks_no_diagnostic_data_beyond_the_four_documented_keys(): void
    {
        Cache::forever('cron:last_tick', now()->toIso8601String());

        $body = $this->getJson('/api/health/ping')->assertOk()->json();

        $this->assertEqualsCanonicalizing(
            ['ok', 'cron_last_tick_seconds_ago', 'queue_depth', 'failed_jobs'],
            array_keys($body),
        );
    }

    /**
     * The one case where the status code is NOT 200. A dead database is the
     * failure only a pull monitor can see, and it must not render Laravel's
     * debug exception page (DB host, port and schema) on an auth-free route.
     */
    public function test_a_dead_database_returns_a_clean_503_and_no_stack_trace(): void
    {
        config(['app.debug' => true]);
        DB::shouldReceive('table')->andThrow(new \RuntimeException('Connection refused'));

        $res = $this->getJson('/api/health/ping')->assertStatus(503);

        $this->assertFalse($res->json('ok'));
        $this->assertNull($res->json('queue_depth'));
        $this->assertStringNotContainsString('Connection refused', $res->getContent());
        $this->assertStringNotContainsString('trace', $res->getContent());
    }

    /**
     * The authenticated /api/diagnostics route is the rich one and must stay
     * admin-only — this guards against someone "simplifying" the two into one.
     */
    public function test_the_rich_diagnostics_route_is_still_gated(): void
    {
        $this->getJson('/api/diagnostics')->assertUnauthorized();

        $bidder = User::factory()->bidder()->create();
        $this->actingAs($bidder, 'sanctum')->getJson('/api/diagnostics')->assertForbidden();
    }
}
