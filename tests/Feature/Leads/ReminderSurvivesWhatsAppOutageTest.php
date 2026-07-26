<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\AppNotification;
use App\Models\Lead;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The reminder system used to die whenever the OpenClaw/WhatsApp tunnel went
 * down — which, on production, it does: WhatsApp last delivered on
 * 2026-07-22 and every attempt since failed with ERR_NGROK_3200.
 *
 * Three separate couplings caused that, and each one is pinned here:
 *   1. runForTenant() returned early when openclaw_url was unset.
 *   2. Reminder eligibility required a BidderNotified log, which is only
 *      written after a SUCCESSFUL WhatsApp send.
 *   3. The Web Push mirror sat after the WhatsApp call inside one try block,
 *      so a throw skipped it entirely.
 *
 * Web Push is free, needs no Mac and cannot be banned. It is the primary
 * channel now; WhatsApp is a bonus. These tests fail if that ever inverts
 * again.
 */
class ReminderSurvivesWhatsAppOutageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic daytime Pakistan time (UTC 10:00 -> PKT 15:00).
        Carbon::setTestNow('2026-07-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A ready lead that was alerted on $minutesAgo, via the in-app/push path only. */
    protected function alertedLead(int $minutesAgo, array $overrides = []): Lead
    {
        $lead = Lead::factory()->create(array_merge([
            'status' => LeadStatus::Ready,
            'score' => 9,
            'posted_at' => now()->subHours(1),
        ], $overrides));

        // This is what ScoreLeadJob creates for every ready lead BEFORE it
        // ever attempts WhatsApp.
        $notification = AppNotification::create([
            'type' => 'lead',
            'level' => 'success',
            'title' => "New {$lead->score}/10 lead",
            'body' => $lead->title,
            'url' => "/leads/{$lead->id}",
            'lead_id' => $lead->id,
        ]);
        $notification->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $lead;
    }

    private function remindersFor(Lead $lead): int
    {
        return ActivityLog::query()
            ->where('type', 'lead_reminder_sent')
            ->where('subject_id', $lead->id)
            ->count();
    }

    public function test_the_45_minute_reminder_fires_with_whatsapp_completely_unconfigured(): void
    {
        // No openclaw_url, no bidder_whatsapp at all — the exact state that
        // previously returned early and sent nothing, ever.
        $lead = $this->alertedLead(45);

        $this->artisan('leads:send-reminders')->assertExitCode(0);

        $this->assertSame(1, $this->remindersFor($lead), 'reminder must fire without WhatsApp configured');
        $this->assertDatabaseHas('app_notifications', [
            'type' => 'reminder',
            'lead_id' => $lead->id,
        ]);
    }

    public function test_the_reminder_still_fires_when_the_whatsapp_tunnel_is_down(): void
    {
        app(SettingsService::class)->setMany([
            'openclaw_url' => 'https://openclaw.test',
            'openclaw_token' => 'token',
            'bidder_whatsapp' => '+923101111571',
        ]);

        // Reproduce the live production failure exactly.
        Http::fake([
            'openclaw.test/*' => Http::response(
                'The endpoint supernaturalistic-incongruent-harris.ngrok-free.dev is offline. ERR_NGROK_3200',
                404,
            ),
        ]);

        $lead = $this->alertedLead(45);

        $this->artisan('leads:send-reminders')->assertExitCode(0);

        // WhatsApp was attempted and failed...
        Http::assertSentCount(1);

        // ...and the reminder still went out on the channel that works.
        $this->assertSame(1, $this->remindersFor($lead), 'a dead tunnel must not suppress the Web Push reminder');
        $this->assertDatabaseHas('app_notifications', [
            'type' => 'reminder',
            'lead_id' => $lead->id,
        ]);
    }

    public function test_eligibility_does_not_require_a_successful_whatsapp_send(): void
    {
        // No bidder_notified activity log anywhere — under the old logic this
        // lead could never become reminder-eligible.
        $lead = $this->alertedLead(45);

        $this->assertDatabaseMissing('activity_logs', [
            'type' => 'bidder_notified',
            'subject_id' => $lead->id,
        ]);

        $this->artisan('leads:send-reminders');

        $this->assertSame(1, $this->remindersFor($lead));
    }

    public function test_marking_the_lead_bid_stops_the_90_minute_reminder(): void
    {
        $lead = $this->alertedLead(45);

        // 45-minute reminder fires.
        $this->artisan('leads:send-reminders');
        $this->assertSame(1, $this->remindersFor($lead));

        // Operator bids it. Time rolls on to the 90-minute mark.
        $lead->update(['status' => LeadStatus::Sent]);
        Carbon::setTestNow(now()->addMinutes(45));

        $this->artisan('leads:send-reminders');

        $this->assertSame(1, $this->remindersFor($lead), 'a bid lead must never get its second reminder');
    }

    public function test_the_full_cadence_is_45_then_90_and_never_a_third(): void
    {
        $lead = $this->alertedLead(45);

        $this->artisan('leads:send-reminders');
        $this->assertSame(1, $this->remindersFor($lead), 'first reminder at 45 min');

        // 70 minutes in — too early for the second.
        Carbon::setTestNow(now()->addMinutes(25));
        $this->artisan('leads:send-reminders');
        $this->assertSame(1, $this->remindersFor($lead), 'no second reminder before 90 min');

        // 90 minutes in.
        Carbon::setTestNow(now()->addMinutes(20));
        $this->artisan('leads:send-reminders');
        $this->assertSame(2, $this->remindersFor($lead), 'second reminder at 90 min');

        // Hours later — still exactly two, forever.
        Carbon::setTestNow(now()->addHours(3));
        $this->artisan('leads:send-reminders');
        $this->assertSame(2, $this->remindersFor($lead), 'never a third reminder');
    }

    public function test_quiet_hours_still_suppress_push_reminders_too(): void
    {
        $lead = $this->alertedLead(45);

        // UTC 20:00 -> PKT 01:00, inside the 23:00-07:00 quiet window.
        Carbon::setTestNow('2026-07-20 20:00:00');

        $this->artisan('leads:send-reminders');

        $this->assertSame(0, $this->remindersFor($lead), 'quiet hours must silence every channel, not just WhatsApp');
    }

    public function test_muted_mode_still_suppresses_push_reminders_too(): void
    {
        app(SettingsService::class)->set('whatsapp_alert_mode', 'muted');
        $lead = $this->alertedLead(45);

        $this->artisan('leads:send-reminders');

        $this->assertSame(0, $this->remindersFor($lead), 'mute is a global quiet switch, not a WhatsApp-only one');
    }
}
