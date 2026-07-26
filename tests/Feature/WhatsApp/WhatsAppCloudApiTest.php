<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\SettingsService;
use App\Services\WhatsApp\WhatsAppCloudService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Meta's official WhatsApp Business Cloud API — the path that finally removes
 * the Mac/ngrok dependency, and the only one that can RECEIVE messages, which
 * is what makes BID/SKIP/PAUSE replies possible.
 *
 * The webhook tests matter most: the endpoint is necessarily public, so an
 * unverified POST must never be able to mark a lead bid or mute the
 * operator's alerts.
 */
class WhatsAppCloudApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-app-secret';

    private const VERIFY = 'test-verify-token';

    private const PHONE_ID = '1234567890';

    private const OPERATOR = '923101111571';

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->setMany([
            'whatsapp_cloud_enabled' => true,
            'whatsapp_phone_number_id' => self::PHONE_ID,
            'whatsapp_access_token' => 'EAAG-permanent-token',
            'whatsapp_app_secret' => self::SECRET,
            'whatsapp_verify_token' => self::VERIFY,
            'bidder_whatsapp' => '+'.self::OPERATOR,
        ]);
    }

    private function inboundPayload(string $text): array
    {
        return ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'messages' => [[
                'from' => self::OPERATOR,
                'type' => 'text',
                'text' => ['body' => $text],
            ]],
        ]]]]]];
    }

    /** POST the way Meta does: signed HMAC over the exact raw body. */
    private function postSigned(array $payload, ?string $secret = null)
    {
        $raw = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $raw, $secret ?? self::SECRET);

        return $this->call(
            'POST', '/api/webhooks/whatsapp', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature],
            $raw,
        );
    }

    // ------------------------------------------------------------ handshake

    public function test_the_subscription_handshake_echoes_the_challenge_for_the_right_token(): void
    {
        $this->get('/api/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token='.self::VERIFY.'&hub_challenge=abc123')
            ->assertOk()
            ->assertSee('abc123');
    }

    public function test_the_handshake_is_refused_for_a_wrong_token(): void
    {
        $this->get('/api/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=abc123')
            ->assertStatus(403);
    }

    // ------------------------------------------------------------- security

    public function test_an_unsigned_webhook_cannot_change_anything(): void
    {
        $lead = Lead::factory()->create(['status' => LeadStatus::Ready, 'score' => 9]);

        $this->postJson('/api/webhooks/whatsapp', $this->inboundPayload('BID'))
            ->assertStatus(403);

        $this->assertSame(LeadStatus::Ready, $lead->fresh()->status, 'an unsigned request must never act');
    }

    public function test_a_forged_signature_cannot_change_anything(): void
    {
        $lead = Lead::factory()->create(['status' => LeadStatus::Ready, 'score' => 9]);

        $this->postSigned($this->inboundPayload('BID'), 'the-wrong-secret')->assertStatus(403);

        $this->assertSame(LeadStatus::Ready, $lead->fresh()->status);
    }

    public function test_a_message_from_a_stranger_is_ignored(): void
    {
        $lead = Lead::factory()->create(['status' => LeadStatus::Ready, 'score' => 9]);
        Http::fake();

        $payload = $this->inboundPayload('BID');
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['from'] = '999999999999';

        $this->postSigned($payload)->assertOk();

        $this->assertSame(LeadStatus::Ready, $lead->fresh()->status, 'only the operator may drive the workspace');
    }

    // ------------------------------------------------------------- commands

    public function test_bid_marks_the_newest_open_lead_and_stops_its_reminders(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $lead = Lead::factory()->create(['status' => LeadStatus::Ready, 'score' => 9, 'title' => 'Laravel API']);

        $this->postSigned($this->inboundPayload('BID'))->assertOk();

        // No longer `ready`, so the reminder sweep skips it entirely.
        $this->assertSame(LeadStatus::Sent, $lead->fresh()->status);
    }

    public function test_skip_archives_the_newest_open_lead(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $lead = Lead::factory()->create(['status' => LeadStatus::Ready, 'score' => 9]);

        $this->postSigned($this->inboundPayload('skip'))->assertOk();

        $this->assertSame(LeadStatus::Archived, $lead->fresh()->status);
    }

    public function test_pause_mute_and_resume_drive_the_global_alert_mode(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $settings = app(SettingsService::class);

        $this->postSigned($this->inboundPayload('PAUSE'))->assertOk();
        $this->assertSame('paused', $settings->get('whatsapp_alert_mode'));

        $this->postSigned($this->inboundPayload('mute'))->assertOk();
        $this->assertSame('muted', $settings->get('whatsapp_alert_mode'));

        $this->postSigned($this->inboundPayload('RESUME'))->assertOk();
        $this->assertSame('normal', $settings->get('whatsapp_alert_mode'));
    }

    public function test_an_unknown_message_gets_a_short_menu_not_an_open_ended_ai_reply(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        $this->postSigned($this->inboundPayload('what do you think about the economy?'))->assertOk();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains((string) data_get($body, 'text.body'), 'Commands:');
        });
    }

    // -------------------------------------------------------------- sending

    public function test_outside_the_service_window_an_alert_uses_the_utility_template(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        app(WhatsAppCloudService::class)->sendAlert('Fresh 9/10 lead', ['9/10', 'Laravel API', '$1,200', '4 minutes ago']);

        Http::assertSent(fn ($r) => data_get($r->data(), 'type') === 'template'
            && data_get($r->data(), 'template.name') === 'fresh_lead');
    }

    public function test_inside_the_service_window_an_alert_uses_free_form_text(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        // The operator messaged us a minute ago.
        app(SettingsService::class)->set('whatsapp_last_inbound_at', now()->subMinute()->toIso8601String());

        app(WhatsAppCloudService::class)->sendAlert('Fresh 9/10 lead');

        Http::assertSent(fn ($r) => data_get($r->data(), 'type') === 'text');
    }

    public function test_the_dispatcher_prefers_the_cloud_api_over_the_openclaw_bridge(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]]),
            'openclaw.test/*' => Http::response(['success' => true]),
        ]);
        app(SettingsService::class)->setMany([
            'openclaw_url' => 'https://openclaw.test',
            'openclaw_token' => 'token',
        ]);

        $lead = Lead::factory()->create([
            'status' => LeadStatus::Ready, 'score' => 9, 'posted_at' => now()->subMinutes(3),
        ]);

        $result = app(NotificationDispatcher::class)->leadReady($lead);

        $this->assertContains('whatsapp_cloud', $result['channels']);
        $this->assertNotContains('whatsapp', $result['channels'], 'the Mac bridge must be bypassed entirely');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openclaw.test'));
    }

    public function test_a_cloud_api_failure_never_takes_down_the_alert(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $lead = Lead::factory()->create([
            'status' => LeadStatus::Ready, 'score' => 9, 'posted_at' => now()->subMinutes(3),
        ]);

        $result = app(NotificationDispatcher::class)->leadReady($lead);

        $this->assertTrue($result['alerted'], 'bell and push still succeeded');
        $this->assertDatabaseHas('app_notifications', ['lead_id' => $lead->id, 'type' => 'lead']);
    }

    public function test_it_stays_inert_until_every_credential_is_present(): void
    {
        app(SettingsService::class)->set('whatsapp_access_token', '');

        $this->assertFalse(app(WhatsAppCloudService::class)->isConfigured());
        $this->assertNull(app(WhatsAppCloudService::class)->sendAlert('nothing should be sent'));
    }
}
