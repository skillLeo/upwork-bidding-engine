<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendLeadRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->setMany([
            'openclaw_url' => 'https://openclaw.test',
            'openclaw_token' => 'token',
            'bidder_whatsapp' => '+923101111571',
        ]);

        // Fixed, deterministic daytime Pakistan time (UTC 10:00 -> PKT
        // 15:00) so the quiet-hours gate never depends on when the test
        // suite happens to run.
        Carbon::setTestNow('2026-07-20 10:00:00');

        Http::fake(['openclaw.test/*' => Http::response(['success' => true])]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function notifiedLead(array $overrides = []): Lead
    {
        $lead = Lead::factory()->create(array_merge([
            'status' => LeadStatus::Ready,
            'score' => 9,
            'posted_at' => now()->subHours(1),
        ], $overrides));

        ActivityLog::record('bidder_notified', subject: $lead, meta: []);

        return $lead;
    }

    public function test_no_reminder_before_45_minutes(): void
    {
        $lead = $this->notifiedLead();
        ActivityLog::where('subject_id', $lead->id)->update(['created_at' => now()->subMinutes(30)]);

        $this->artisan('leads:send-reminders');

        Http::assertNothingSent();
        $this->assertDatabaseMissing('activity_logs', ['type' => 'lead_reminder_sent']);
    }

    public function test_first_reminder_fires_at_45_minutes(): void
    {
        $lead = $this->notifiedLead();
        ActivityLog::where('subject_id', $lead->id)->update(['created_at' => now()->subMinutes(45)]);

        $this->artisan('leads:send-reminders');

        Http::assertSentCount(1);
        $this->assertDatabaseHas('activity_logs', [
            'type' => 'lead_reminder_sent',
            'subject_id' => $lead->id,
        ]);
    }

    public function test_second_reminder_only_fires_at_90_minutes_not_before(): void
    {
        $lead = $this->notifiedLead();
        ActivityLog::where('subject_id', $lead->id)->update(['created_at' => now()->subMinutes(70)]);
        ActivityLog::record('lead_reminder_sent', subject: $lead, meta: ['reminder_number' => 1]);

        $this->artisan('leads:send-reminders');

        Http::assertNothingSent();
    }

    public function test_second_reminder_fires_at_90_minutes(): void
    {
        $lead = $this->notifiedLead();
        ActivityLog::where('subject_id', $lead->id)->where('type', 'bidder_notified')->update(['created_at' => now()->subMinutes(90)]);
        ActivityLog::record('lead_reminder_sent', subject: $lead, meta: ['reminder_number' => 1]);

        $this->artisan('leads:send-reminders');

        Http::assertSentCount(1);
        $this->assertEquals(2, ActivityLog::where('type', 'lead_reminder_sent')->where('subject_id', $lead->id)->count());
    }

    public function test_never_a_third_reminder(): void
    {
        $lead = $this->notifiedLead();
        ActivityLog::where('subject_id', $lead->id)->where('type', 'bidder_notified')->update(['created_at' => now()->subHours(5)]);
        ActivityLog::record('lead_reminder_sent', subject: $lead, meta: ['reminder_number' => 1]);
        ActivityLog::record('lead_reminder_sent', subject: $lead, meta: ['reminder_number' => 2]);

        $this->artisan('leads:send-reminders');

        Http::assertNothingSent();
    }

    public function test_stops_once_lead_is_no_longer_ready(): void
    {
        $lead = $this->notifiedLead(['status' => LeadStatus::Sent]);
        ActivityLog::where('subject_id', $lead->id)->update(['created_at' => now()->subMinutes(45)]);

        $this->artisan('leads:send-reminders');

        Http::assertNothingSent();
    }

    public function test_below_score_threshold_is_excluded(): void
    {
        $lead = $this->notifiedLead(['score' => 7]);
        ActivityLog::where('subject_id', $lead->id)->update(['created_at' => now()->subMinutes(45)]);

        $this->artisan('leads:send-reminders');

        Http::assertNothingSent();
    }

    public function test_lead_older_than_six_hours_is_excluded(): void
    {
        $lead = $this->notifiedLead(['posted_at' => now()->subHours(7)]);
        ActivityLog::where('subject_id', $lead->id)->update(['created_at' => now()->subMinutes(45)]);

        $this->artisan('leads:send-reminders');

        Http::assertNothingSent();
    }

    public function test_no_original_card_sent_means_no_reminder(): void
    {
        Lead::factory()->create(['status' => LeadStatus::Ready, 'score' => 9, 'posted_at' => now()->subHours(1)]);

        $this->artisan('leads:send-reminders');

        Http::assertNothingSent();
    }

    public function test_quiet_hours_suppress_an_otherwise_due_reminder(): void
    {
        $lead = $this->notifiedLead();
        ActivityLog::where('subject_id', $lead->id)->update(['created_at' => now()->subMinutes(45)]);

        // UTC 20:00 -> PKT 01:00, inside the 11pm-7am quiet window.
        Carbon::setTestNow('2026-07-20 20:00:00');

        $this->artisan('leads:send-reminders');

        Http::assertNothingSent();
    }
}
