<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadStatus;
use App\Jobs\NotifyBidderJob;
use App\Models\Lead;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotifyFreshnessTest extends TestCase
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
    }

    public function test_stale_lead_is_kept_on_dashboard_but_never_rings_the_phone(): void
    {
        Http::fake(['openclaw.test/*' => Http::response(['success' => true])]);

        $lead = Lead::factory()->create(['status' => LeadStatus::Ready, 'score' => 8, 'posted_at' => now()->subDays(3)]);

        NotifyBidderJob::dispatchSync($lead->id);

        Http::assertNothingSent();
        $lead->refresh();
        $this->assertStringContainsString('stale at scoring time', (string) $lead->notification_skipped_reason);
        $this->assertEquals(LeadStatus::Ready, $lead->status);
        $this->assertDatabaseHas('activity_logs', ['type' => 'notification_skipped']);
        $this->assertDatabaseMissing('activity_logs', ['type' => 'bidder_notified']);
    }

    public function test_fresh_lead_notifies_and_clears_any_old_failure_badge(): void
    {
        Http::fake(['openclaw.test/*' => Http::response(['success' => true])]);

        $lead = Lead::factory()->create([
            'status' => LeadStatus::Ready,
            'score' => 8,
            'posted_at' => now()->subHours(5),
            'notify_error' => 'old tunnel failure',
        ]);

        NotifyBidderJob::dispatchSync($lead->id);

        $this->assertDatabaseHas('activity_logs', ['type' => 'bidder_notified']);
        $this->assertNull($lead->fresh()->notify_error);
    }

    public function test_zero_cutoff_disables_the_gate(): void
    {
        app(SettingsService::class)->set('notification_freshness_hours', 0);
        Http::fake(['openclaw.test/*' => Http::response(['success' => true])]);

        $lead = Lead::factory()->create(['status' => LeadStatus::Ready, 'score' => 8, 'posted_at' => now()->subDays(6)]);

        NotifyBidderJob::dispatchSync($lead->id);

        $this->assertDatabaseHas('activity_logs', ['type' => 'bidder_notified']);
        $this->assertNull($lead->fresh()->notification_skipped_reason);
    }

    public function test_muted_mode_blocks_even_a_brand_new_lead_card(): void
    {
        app(SettingsService::class)->set('whatsapp_alert_mode', 'muted');
        Http::fake(['openclaw.test/*' => Http::response(['success' => true])]);

        $lead = Lead::factory()->create(['status' => LeadStatus::Ready, 'score' => 9, 'posted_at' => now()->subHours(1)]);

        NotifyBidderJob::dispatchSync($lead->id);

        Http::assertNothingSent();
        $this->assertDatabaseHas('activity_logs', ['type' => 'notification_skipped']);
        $this->assertDatabaseMissing('activity_logs', ['type' => 'bidder_notified']);
    }

    public function test_paused_mode_still_sends_a_brand_new_lead_card(): void
    {
        app(SettingsService::class)->set('whatsapp_alert_mode', 'paused');
        Http::fake(['openclaw.test/*' => Http::response(['success' => true])]);

        $lead = Lead::factory()->create(['status' => LeadStatus::Ready, 'score' => 9, 'posted_at' => now()->subHours(1)]);

        NotifyBidderJob::dispatchSync($lead->id);

        $this->assertDatabaseHas('activity_logs', ['type' => 'bidder_notified']);
    }

    public function test_exhausted_failure_sets_the_red_badge_on_the_lead(): void
    {
        Http::fake(['openclaw.test/*' => Http::response(['error' => 'tunnel dead'], 502)]);

        $lead = Lead::factory()->create(['status' => LeadStatus::Ready, 'score' => 8, 'posted_at' => now()->subHours(2)]);

        try {
            NotifyBidderJob::dispatchSync($lead->id);
            $this->fail('Expected the notify failure to throw.');
        } catch (\Throwable) {
            // Sync driver runs failed() then rethrows — that's the path
            // that must leave the badge behind.
        }

        $this->assertNotNull($lead->fresh()->notify_error);
        $this->assertDatabaseHas('activity_logs', ['type' => 'bidder_notify_failed']);
    }
}
