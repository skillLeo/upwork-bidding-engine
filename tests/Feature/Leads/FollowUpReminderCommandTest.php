<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FollowUpReminderCommandTest extends TestCase
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

        Http::fake(['openclaw.test/*' => Http::response(['success' => true])]);
    }

    protected function overdueSentLead(): Lead
    {
        return Lead::factory()->create([
            'status' => LeadStatus::Sent,
            'updated_at' => now()->subDays(5),
        ]);
    }

    public function test_sends_a_follow_up_in_normal_mode(): void
    {
        $this->overdueSentLead();

        $this->artisan('leads:follow-up-reminders');

        Http::assertSentCount(1);
    }

    public function test_paused_mode_suppresses_follow_ups(): void
    {
        app(SettingsService::class)->set('whatsapp_alert_mode', 'paused');
        $this->overdueSentLead();

        $this->artisan('leads:follow-up-reminders');

        Http::assertNothingSent();
    }

    public function test_muted_mode_suppresses_follow_ups(): void
    {
        app(SettingsService::class)->set('whatsapp_alert_mode', 'muted');
        $this->overdueSentLead();

        $this->artisan('leads:follow-up-reminders');

        Http::assertNothingSent();
    }
}
