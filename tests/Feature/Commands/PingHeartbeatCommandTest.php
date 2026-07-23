<?php

namespace Tests\Feature\Commands;

use App\Console\Commands\PingHeartbeatCommand;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PingHeartbeatCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_op_when_unconfigured(): void
    {
        Http::fake();

        $this->artisan('ops:heartbeat')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull(Cache::get(PingHeartbeatCommand::LAST_ATTEMPT_KEY));
    }

    public function test_pings_and_records_success(): void
    {
        app(SettingsService::class)->set('heartbeat_ping_url', 'https://hc-ping.com/test-uuid');
        Http::fake(['hc-ping.com/*' => Http::response('OK', 200)]);

        $this->artisan('ops:heartbeat')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'hc-ping.com'));
        $this->assertNotNull(Cache::get(PingHeartbeatCommand::LAST_ATTEMPT_KEY));
        $this->assertSame('ok', Cache::get(PingHeartbeatCommand::LAST_RESULT_KEY));
    }

    public function test_a_dead_endpoint_never_fails_the_command_and_records_the_failure(): void
    {
        app(SettingsService::class)->set('heartbeat_ping_url', 'https://dead.invalid.test/ping');
        Http::fake(['dead.invalid.test/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Could not resolve host')]);

        // The command must still exit SUCCESS - a dead monitor must never
        // break the scheduler tick that calls it.
        $this->artisan('ops:heartbeat')->assertSuccessful();

        $this->assertNotNull(Cache::get(PingHeartbeatCommand::LAST_ATTEMPT_KEY));
        $this->assertStringContainsString('failed', (string) Cache::get(PingHeartbeatCommand::LAST_RESULT_KEY));
    }

    public function test_a_non_2xx_response_is_recorded_as_failed(): void
    {
        app(SettingsService::class)->set('heartbeat_ping_url', 'https://hc-ping.com/test-uuid');
        Http::fake(['hc-ping.com/*' => Http::response('nope', 500)]);

        $this->artisan('ops:heartbeat')->assertSuccessful();

        $this->assertStringContainsString('HTTP 500', (string) Cache::get(PingHeartbeatCommand::LAST_RESULT_KEY));
    }
}
