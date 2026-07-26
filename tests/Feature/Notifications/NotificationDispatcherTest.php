<?php

namespace Tests\Feature\Notifications;

use App\Enums\LeadStatus;
use App\Models\AppNotification;
use App\Models\Lead;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The dispatcher is the single gate every lead alert passes through. These
 * tests pin the rules that used to live scattered across ScoreLeadJob,
 * NotifyBidderJob and NotificationService — which is how channels got
 * silently skipped and how the same lead could be alerted twice.
 */
class NotificationDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private function dispatcher(): NotificationDispatcher
    {
        return app(NotificationDispatcher::class);
    }

    private function lead(array $overrides = []): Lead
    {
        return Lead::factory()->create(array_merge([
            'status' => LeadStatus::Ready,
            'score' => 9,
            'title' => 'Laravel API integration',
            'budget' => '$1,200',
            'posted_at' => now()->subMinutes(4),
        ], $overrides));
    }

    private function bellRows(Lead $lead): int
    {
        return AppNotification::where('type', 'lead')->where('lead_id', $lead->id)->count();
    }

    // ------------------------------------------------------------- threshold

    public function test_a_lead_below_the_score_threshold_never_alerts(): void
    {
        app(SettingsService::class)->set('notify_score_min', 8);
        $lead = $this->lead(['score' => 7]);

        $result = $this->dispatcher()->leadReady($lead);

        $this->assertFalse($result['alerted']);
        $this->assertStringContainsString('below the alert threshold', (string) $result['reason']);
        $this->assertSame(0, $this->bellRows($lead));
    }

    public function test_the_threshold_is_operator_configurable(): void
    {
        app(SettingsService::class)->set('notify_score_min', 7);
        $lead = $this->lead(['score' => 7]);

        $this->assertTrue($this->dispatcher()->leadReady($lead)['alerted']);
        $this->assertSame(1, $this->bellRows($lead));
    }

    // ---------------------------------------------------------------- dedupe

    public function test_rescoring_a_lead_does_not_re_notify_on_any_channel(): void
    {
        $lead = $this->lead();

        $first = $this->dispatcher()->leadReady($lead);
        $this->assertTrue($first['alerted']);

        // Scoring runs again — a rescore, a retried job, a duplicate webhook.
        $second = $this->dispatcher()->leadReady($lead);
        $third = $this->dispatcher()->leadReady($lead->fresh());

        $this->assertFalse($second['alerted'], 'a rescore must not re-alert');
        $this->assertFalse($third['alerted']);
        $this->assertSame('already alerted', $second['reason']);
        $this->assertSame(1, $this->bellRows($lead), 'exactly one bell row per lead, ever');
    }

    // ------------------------------------------------------------- freshness

    public function test_a_stale_lead_reaches_the_bell_but_never_rings_the_phone(): void
    {
        app(SettingsService::class)->set('notification_freshness_hours', 48);
        $lead = $this->lead(['posted_at' => now()->subHours(72)]);

        $result = $this->dispatcher()->leadReady($lead);

        $this->assertFalse($result['alerted'], 'a stale lead must not interrupt the operator');
        $this->assertSame(['bell'], $result['channels'], 'but it must still be visible on the dashboard');
        $this->assertSame(1, $this->bellRows($lead));

        $this->assertStringContainsString('stale at scoring time', (string) $lead->fresh()->notification_skipped_reason);
        $this->assertDatabaseHas('activity_logs', ['type' => 'notification_skipped', 'subject_id' => $lead->id]);
    }

    public function test_a_freshness_gate_of_zero_disables_the_gate(): void
    {
        app(SettingsService::class)->set('notification_freshness_hours', 0);
        $lead = $this->lead(['posted_at' => now()->subDays(30)]);

        $this->assertTrue($this->dispatcher()->leadReady($lead)['alerted']);
    }

    // ------------------------------------------------------------------ mute

    public function test_muted_alerts_reach_the_bell_but_not_the_phone(): void
    {
        app(SettingsService::class)->set('whatsapp_alert_mode', 'muted');
        $lead = $this->lead();

        $result = $this->dispatcher()->leadReady($lead);

        $this->assertFalse($result['alerted']);
        $this->assertSame(['bell'], $result['channels']);
        $this->assertSame(1, $this->bellRows($lead));
        $this->assertStringContainsString('muted', (string) $lead->fresh()->notification_skipped_reason);
    }

    // -------------------------------------------------------------- channels

    public function test_push_fires_without_whatsapp_configured(): void
    {
        // No openclaw_url / bidder_whatsapp at all.
        $lead = $this->lead();

        $result = $this->dispatcher()->leadReady($lead);

        $this->assertTrue($result['alerted']);
        $this->assertSame(['bell', 'push'], $result['channels'], 'WhatsApp being absent must not stop the alert');
    }

    public function test_whatsapp_is_added_as_a_secondary_channel_when_configured(): void
    {
        app(SettingsService::class)->setMany([
            'openclaw_url' => 'https://openclaw.test',
            'openclaw_token' => 'token',
            'bidder_whatsapp' => '+923101111571',
        ]);
        Http::fake(['openclaw.test/*' => Http::response(['success' => true])]);

        $result = $this->dispatcher()->leadReady($this->lead());

        $this->assertSame(['bell', 'push', 'whatsapp'], $result['channels']);
    }

    public function test_a_dead_whatsapp_tunnel_never_prevents_the_alert(): void
    {
        app(SettingsService::class)->setMany([
            'openclaw_url' => 'https://openclaw.test',
            'openclaw_token' => 'token',
            'bidder_whatsapp' => '+923101111571',
        ]);
        // The live production failure.
        Http::fake(['openclaw.test/*' => Http::response('ERR_NGROK_3200 offline', 404)]);

        $lead = $this->lead();
        $result = $this->dispatcher()->leadReady($lead);

        $this->assertTrue($result['alerted'], 'the alert stands even though WhatsApp failed');
        $this->assertSame(1, $this->bellRows($lead));
    }

    // ----------------------------------------------------------- one wording

    public function test_every_channel_shares_one_message_format(): void
    {
        $lead = $this->lead(['score' => 9, 'title' => 'Laravel API', 'budget' => '$1,200']);

        $this->assertSame('Fresh 9/10 lead', $this->dispatcher()->headline($lead));

        $summary = $this->dispatcher()->summary($lead);
        $this->assertStringContainsString('Laravel API', $summary);
        $this->assertStringContainsString('$1,200', $summary);
        $this->assertStringContainsString('posted', $summary);
        $this->assertStringContainsString(' · ', $summary);

        // And the row the bell/push actually carry uses exactly that wording.
        $this->dispatcher()->leadReady($lead);
        $row = AppNotification::where('lead_id', $lead->id)->where('type', 'lead')->firstOrFail();
        $this->assertSame('Fresh 9/10 lead', $row->title);
        $this->assertSame($summary, $row->body);
        $this->assertSame("/leads/{$lead->id}", $row->url, 'one tap from alert to the lead');
    }
}
