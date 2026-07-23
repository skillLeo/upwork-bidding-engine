<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HeartbeatTestPingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_saved_url_is_pinged_and_the_status_reported(): void
    {
        $admin = User::factory()->admin()->create();
        app(SettingsService::class)->set('heartbeat_ping_url', 'https://hc-ping.com/test-uuid');
        Http::fake(['hc-ping.com/*' => Http::response('OK', 200)]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/settings/test/heartbeat')
            ->assertOk()
            ->assertJsonPath('data.success', true);

        Http::assertSent(fn ($request) => $request->url() === 'https://hc-ping.com/test-uuid');
    }

    public function test_an_unsaved_url_says_so_instead_of_pretending_to_ping(): void
    {
        $admin = User::factory()->admin()->create();
        Http::fake();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/settings/test/heartbeat')
            ->assertOk()
            ->assertJsonPath('data.success', false);

        Http::assertNothingSent();
    }

    public function test_a_non_2xx_monitor_response_is_reported_as_a_failure_with_its_status(): void
    {
        $admin = User::factory()->admin()->create();
        app(SettingsService::class)->set('heartbeat_ping_url', 'https://hc-ping.com/test-uuid');
        Http::fake(['hc-ping.com/*' => Http::response('nope', 404)]);

        $res = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/settings/test/heartbeat')
            ->assertOk();

        $this->assertFalse($res->json('data.success'));
        $this->assertStringContainsString('404', $res->json('data.message'));
    }

    public function test_a_bidder_cannot_trigger_the_ping(): void
    {
        $bidder = User::factory()->bidder()->create();

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/settings/test/heartbeat')
            ->assertForbidden();
    }

    public function test_a_non_http_scheme_is_rejected_at_save_time(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/settings', ['heartbeat_ping_url' => 'file:///etc/passwd'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('heartbeat_ping_url');
    }

    public function test_an_empty_value_still_saves_and_disables_the_ping(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/settings', ['heartbeat_ping_url' => ''])
            ->assertOk();

        $this->assertSame('', trim((string) app(SettingsService::class)->get('heartbeat_ping_url')));
    }
}
