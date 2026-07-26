<?php

namespace Tests\Feature\Notifications;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\Tenant;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P8 step 6: the OpenClaw WhatsApp bridge is internal-only.
 *
 * It is not a feature that happens to be unconfigured for customers — it is
 * ONE physical, QR-paired WhatsApp session on a company-owned Mac. A customer
 * workspace that reached it would deliver its lead alerts to somebody else's
 * phone, which is why the gate is the tenant's plan and not a settings flag
 * a workspace could be handed by accident.
 */
class InternalOnlyFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function openClawConfigured(): void
    {
        app(SettingsService::class)->setMany([
            'openclaw_url' => 'https://openclaw.test',
            'openclaw_token' => 'token',
            'bidder_whatsapp' => '+923101111571',
        ]);
    }

    private function freshLead(): Lead
    {
        return Lead::factory()->create([
            'status' => LeadStatus::Ready,
            'score' => 9,
            'posted_at' => now()->subMinutes(3),
        ]);
    }

    public function test_the_internal_workspace_still_reaches_the_openclaw_bridge(): void
    {
        $this->assertTrue($this->tenant->isInternal(), 'the founding workspace is the internal one');

        Http::fake(['openclaw.test/*' => Http::response(['success' => true])]);
        $this->openClawConfigured();

        $result = app(NotificationDispatcher::class)->leadReady($this->freshLead());

        $this->assertContains('whatsapp', $result['channels']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'openclaw.test'));
    }

    public function test_a_customer_workspace_never_reaches_the_openclaw_bridge(): void
    {
        $customer = Tenant::create([
            'name' => 'Customer', 'slug' => 'customer', 'plan' => 'free', 'status' => Tenant::STATUS_ACTIVE,
        ]);

        Http::fake(['openclaw.test/*' => Http::response(['success' => true])]);

        $result = Tenancy::runAs($customer, function () {
            // Configured exactly as the internal workspace is — the plan, not
            // the configuration, is what withholds it.
            $this->openClawConfigured();

            return app(NotificationDispatcher::class)->leadReady($this->freshLead());
        });

        $this->assertNotContains('whatsapp', $result['channels'], 'a customer must never drive the company WhatsApp session');
        Http::assertNothingSent();

        // NOT left silent: the bell and Web Push still fired for them.
        $this->assertTrue($result['alerted']);
        $this->assertSame(['bell', 'push'], $result['channels']);
    }

    public function test_a_customer_workspace_still_gets_its_own_cloud_api_number(): void
    {
        $customer = Tenant::create([
            'name' => 'Cloudy', 'slug' => 'cloudy', 'plan' => 'free', 'status' => Tenant::STATUS_ACTIVE,
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]]),
            'openclaw.test/*' => Http::response(['success' => true]),
        ]);

        $result = Tenancy::runAs($customer, function () {
            $this->openClawConfigured();

            // Meta's Cloud API is a PER-WORKSPACE credential — their number,
            // their token — so it is a product feature and stays available.
            app(SettingsService::class)->setMany([
                'whatsapp_cloud_enabled' => true,
                'whatsapp_phone_number_id' => '1234567890',
                'whatsapp_access_token' => 'EAAG-their-own-token',
            ]);

            return app(NotificationDispatcher::class)->leadReady($this->freshLead());
        });

        $this->assertContains('whatsapp_cloud', $result['channels']);
        $this->assertNotContains('whatsapp', $result['channels']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openclaw.test'));
    }

    public function test_the_reminder_sweep_applies_the_same_rule(): void
    {
        $customer = Tenant::create([
            'name' => 'Reminded', 'slug' => 'reminded', 'plan' => 'free', 'status' => Tenant::STATUS_ACTIVE,
        ]);

        Http::fake(['openclaw.test/*' => Http::response(['success' => true])]);

        Tenancy::runAs($customer, function () {
            $this->openClawConfigured();

            $lead = $this->freshLead();
            $lead->update(['posted_at' => now()->subHours(2)]);

            app(NotificationDispatcher::class)->leadReady($lead);
        });

        $this->artisan('leads:send-reminders')->assertExitCode(0);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openclaw.test'));
    }
}
