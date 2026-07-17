<?php

namespace Tests\Feature\Vollna;

use App\Console\Commands\VollnaCheckSilenceCommand;
use App\Jobs\VollnaRejectedAlertJob;
use App\Services\OpsAlertService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeadMansSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected SettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(SettingsService::class);
        $this->settings->set('vollna_webhook_secret', 'test-secret');
        $this->settings->set('openclaw_url', 'https://openclaw.test');
        $this->settings->set('openclaw_token', 'token');
        $this->settings->set('bidder_whatsapp', '+15550001111');
    }

    public function test_first_run_initializes_timestamp_without_alerting(): void
    {
        Http::fake();

        $this->artisan('vollna:check-silence')->assertSuccessful();

        $this->assertNotNull($this->settings->get('vollna_last_webhook_at'));
        Http::assertNothingSent();
    }

    public function test_no_alert_when_last_delivery_is_within_threshold(): void
    {
        Http::fake();
        $this->settings->set('vollna_last_webhook_at', now()->subHours(2)->toIso8601String());

        $this->artisan('vollna:check-silence')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_alerts_once_per_incident_when_silent_past_threshold(): void
    {
        Http::fake(['*' => Http::response(['success' => true])]);
        $this->settings->set('vollna_last_webhook_at', now()->subHours(10)->toIso8601String());

        $this->artisan('vollna:check-silence')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['skill'] === 'send_whatsapp_message'
            && str_contains($request['message'], 'no Vollna webhook deliveries'));
        $this->assertDatabaseHas('activity_logs', ['type' => 'vollna_silence_alert']);

        // Second hourly run during the same incident: no second message.
        $this->artisan('vollna:check-silence')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_respects_configured_threshold(): void
    {
        Http::fake(['*' => Http::response(['success' => true])]);
        $this->settings->set('vollna_silence_alert_hours', 2);
        $this->settings->set('vollna_last_webhook_at', now()->subHours(3)->toIso8601String());

        $this->artisan('vollna:check-silence')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_incident_flag_is_not_set_when_no_alert_channel_succeeds(): void
    {
        // No WhatsApp number and no mail host: send() has nowhere to go.
        $this->settings->set('bidder_whatsapp', '');
        $this->settings->set('vollna_last_webhook_at', now()->subHours(10)->toIso8601String());

        $this->artisan('vollna:check-silence')->assertFailed();

        $this->assertFalse(Cache::has(VollnaCheckSilenceCommand::ALERTED_CACHE_KEY));
    }

    public function test_authenticated_delivery_stamps_liveness_and_resets_incidents(): void
    {
        Http::fake();
        Cache::forever(VollnaCheckSilenceCommand::ALERTED_CACHE_KEY, 'x');
        Cache::forever(VollnaRejectedAlertJob::ALERTED_CACHE_KEY, 'x');
        $this->settings->set('vollna_last_webhook_at', now()->subHours(10)->toIso8601String());

        // A minimal project that fails hard filters — enough to prove the
        // delivery was authenticated without exercising the scoring path.
        $this->postJson(
            '/api/vollna-hook',
            ['projects' => [['title' => 'Job', 'url' => 'https://www.upwork.com/jobs/x?pid=99']]],
            ['X-Vollna-Secret' => 'test-secret'],
        )->assertStatus(201);

        $this->assertFalse(Cache::has(VollnaCheckSilenceCommand::ALERTED_CACHE_KEY));
        $this->assertFalse(Cache::has(VollnaRejectedAlertJob::ALERTED_CACHE_KEY));
        $this->assertTrue(
            now()->parse($this->settings->get('vollna_last_webhook_at'))->greaterThan(now()->subMinute()),
        );
    }

    public function test_rejected_delivery_dispatches_alert_job_once(): void
    {
        Queue::fake();

        $this->postJson('/api/vollna-hook', ['projects' => []], ['X-Vollna-Secret' => 'wrong'])
            ->assertStatus(401);
        $this->postJson('/api/vollna-hook', ['projects' => []], ['X-Vollna-Secret' => 'wrong'])
            ->assertStatus(401);

        Queue::assertPushed(VollnaRejectedAlertJob::class, 1);
    }

    public function test_rejected_alert_job_sends_once_per_incident(): void
    {
        Http::fake(['*' => Http::response(['success' => true])]);

        (new VollnaRejectedAlertJob('secret_mismatch', '203.0.113.9'))->handle(app(OpsAlertService::class));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['skill'] === 'send_whatsapp_message'
            && str_contains($request['message'], 'secret_mismatch'));
        $this->assertDatabaseHas('activity_logs', ['type' => 'vollna_rejected_alert']);

        (new VollnaRejectedAlertJob('secret_mismatch', '203.0.113.9'))->handle(app(OpsAlertService::class));

        Http::assertSentCount(1);
    }
}
