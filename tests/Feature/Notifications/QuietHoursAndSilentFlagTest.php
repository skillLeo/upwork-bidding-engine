<?php

namespace Tests\Feature\Notifications;

use App\Enums\LeadStatus;
use App\Models\AppNotification;
use App\Models\Lead;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Two things the consolidated Notifications settings section depends on:
 *
 *  - `silent` carries the dispatcher's suppression decision out to the
 *    client, so the in-app toast never pops for a lead the server already
 *    decided must not interrupt anyone. Without it the browser would have to
 *    re-derive the freshness and mute rules in JavaScript — a second copy of
 *    the logic, which is exactly what the dispatcher exists to prevent.
 *  - Quiet hours are operator-configurable rather than hardcoded, and the
 *    default window wraps midnight.
 */
class QuietHoursAndSilentFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function lead(array $overrides = []): Lead
    {
        return Lead::factory()->create(array_merge([
            'status' => LeadStatus::Ready,
            'score' => 9,
            'posted_at' => now()->subMinutes(4),
        ], $overrides));
    }

    private function rowFor(Lead $lead): AppNotification
    {
        return AppNotification::where('type', 'lead')->where('lead_id', $lead->id)->firstOrFail();
    }

    // ---------------------------------------------------------- silent flag

    public function test_a_normal_alert_is_not_silent(): void
    {
        $lead = $this->lead();

        app(NotificationDispatcher::class)->leadReady($lead);

        $this->assertFalse((bool) $this->rowFor($lead)->silent, 'a real alert must be allowed to toast');
    }

    public function test_a_stale_lead_is_recorded_as_silent(): void
    {
        app(SettingsService::class)->set('notification_freshness_hours', 48);
        $lead = $this->lead(['posted_at' => now()->subHours(72)]);

        app(NotificationDispatcher::class)->leadReady($lead);

        $row = $this->rowFor($lead);
        $this->assertTrue((bool) $row->silent, 'a stale lead is listed but must never pop a toast');
    }

    public function test_a_muted_alert_is_recorded_as_silent(): void
    {
        app(SettingsService::class)->set('whatsapp_alert_mode', 'muted');
        $lead = $this->lead();

        app(NotificationDispatcher::class)->leadReady($lead);

        $this->assertTrue((bool) $this->rowFor($lead)->silent);
    }

    public function test_the_api_exposes_the_silent_flag_to_the_client(): void
    {
        app(SettingsService::class)->set('whatsapp_alert_mode', 'muted');
        $lead = $this->lead();
        app(NotificationDispatcher::class)->leadReady($lead);

        $user = \App\Models\User::factory()->admin()->create();

        $body = $this->actingAs($user, 'sanctum')->getJson('/api/notifications')->assertOk()->json('data');

        $row = collect($body)->firstWhere('lead_id', $lead->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['silent'], 'the client needs the server-side decision, not its own copy of the rules');
    }

    // --------------------------------------------------------- quiet hours

    public function test_the_default_quiet_window_wraps_midnight(): void
    {
        $settings = app(SettingsService::class);
        $this->assertSame(23, (int) $settings->get('quiet_hours_start'));
        $this->assertSame(7, (int) $settings->get('quiet_hours_end'));

        // 01:00 Karachi (UTC 20:00) is inside 23->7.
        Carbon::setTestNow('2026-07-20 20:00:00');
        $this->assertTrue($this->quietNow());

        // 15:00 Karachi (UTC 10:00) is outside it.
        Carbon::setTestNow('2026-07-20 10:00:00');
        $this->assertFalse($this->quietNow());
    }

    public function test_a_custom_same_day_window_is_respected(): void
    {
        app(SettingsService::class)->setMany(['quiet_hours_start' => 13, 'quiet_hours_end' => 16]);

        // 15:00 Karachi — inside the custom window.
        Carbon::setTestNow('2026-07-20 10:00:00');
        $this->assertTrue($this->quietNow());

        // 18:00 Karachi — outside it.
        Carbon::setTestNow('2026-07-20 13:00:00');
        $this->assertFalse($this->quietNow());
    }

    public function test_matching_start_and_end_disables_quiet_hours_entirely(): void
    {
        app(SettingsService::class)->setMany(['quiet_hours_start' => 0, 'quiet_hours_end' => 0]);

        // 03:00 Karachi — would be deep inside any normal night window.
        Carbon::setTestNow('2026-07-20 22:00:00');

        $this->assertFalse($this->quietNow(), 'equal bounds must mean "off", never "always quiet"');
    }

    /** Exercises the command's real gate via its public behaviour. */
    private function quietNow(): bool
    {
        $command = new \App\Console\Commands\SendLeadRemindersCommand;
        $method = new \ReflectionMethod($command, 'inQuietHours');
        $method->setAccessible(true);

        return (bool) $method->invoke($command, app(SettingsService::class));
    }
}
