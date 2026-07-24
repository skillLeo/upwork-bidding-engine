<?php

namespace Tests\Feature\Diagnostics;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bidder_can_access_diagnostics_under_settings_view(): void
    {
        // P4: diagnostics moved to settings.view, which a bidder has. The
        // gated-off surface is secrets, not health.
        $bidder = User::factory()->bidder()->create();

        $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/diagnostics')
            ->assertOk();
    }

    public function test_admin_sees_diagnostics_shape(): void
    {
        $admin = User::factory()->admin()->create();
        app(SettingsService::class)->setMany(['openclaw_url' => 'https://openclaw.test', 'openclaw_token' => 'token']);
        Http::fake(['openclaw.test/*' => Http::response(null, 200)]);

        $lead = Lead::factory()->create(['status' => LeadStatus::Ready]);
        ActivityLog::record(ActivityType::LeadScored, subject: $lead, meta: ['score' => 8, 'ready' => true]);
        ActivityLog::record('webhook_rejected', meta: ['source' => 'vollna', 'reason' => 'secret_mismatch']);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/diagnostics');

        $response->assertOk()->assertJsonStructure(['data' => [
            'queue_depth', 'failed_jobs', 'ai_engine_enabled', 'openclaw_online',
            'last_scored_at', 'last_error', 'last_webhook_received_at', 'last_webhook_rejected',
        ]]);

        $this->assertNotNull($response->json('data.last_scored_at'));
        $this->assertEquals('secret_mismatch', $response->json('data.last_webhook_rejected.reason'));
    }
}
