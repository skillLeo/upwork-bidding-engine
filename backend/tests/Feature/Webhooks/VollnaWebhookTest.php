<?php

namespace Tests\Feature\Webhooks;

use App\Enums\LeadStatus;
use App\Jobs\ScoreLeadJob;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VollnaWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('vollna_webhook_secret', 'test-secret');
    }

    public function test_rejects_request_without_a_secret_header(): void
    {
        $this->postJson('/api/vollna-hook', ['title' => 'Job', 'id' => '1'])
            ->assertStatus(401);

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_rejects_request_with_wrong_secret(): void
    {
        $this->postJson(
            '/api/vollna-hook',
            ['title' => 'Job', 'id' => '1'],
            ['X-Vollna-Secret' => 'wrong-secret'],
        )->assertStatus(401);

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_accepts_bearer_token_auth(): void
    {
        // Vollna's own webhook UI only offers None / Bearer Token / Basic Auth —
        // Bearer is the primary real-world path, X-Vollna-Secret is a fallback.
        Queue::fake();

        $response = $this->postJson(
            '/api/vollna-hook',
            ['id' => 'bearer-job-1', 'title' => 'Bearer Auth Job'],
            ['Authorization' => 'Bearer test-secret'],
        );

        $response->assertStatus(201)->assertJsonPath('data.status', 'accepted');
        $this->assertDatabaseHas('leads', ['external_id' => 'bearer-job-1']);
    }

    public function test_rejects_wrong_bearer_token(): void
    {
        $this->postJson(
            '/api/vollna-hook',
            ['id' => '1', 'title' => 'Job'],
            ['Authorization' => 'Bearer wrong-secret'],
        )->assertStatus(401);
    }

    public function test_accepts_valid_payload_creates_lead_and_dispatches_scoring(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/vollna-hook', [
            'id' => 'job-123',
            'title' => 'Laravel Developer Needed',
            'description' => 'Build an API.',
            'budget' => '$500 fixed',
            'proposals' => 5,
            'client' => ['country' => 'USA', 'paymentVerified' => true],
        ], ['X-Vollna-Secret' => 'test-secret']);

        $response->assertStatus(201)->assertJsonPath('data.status', 'accepted');

        $this->assertDatabaseHas('leads', [
            'external_id' => 'job-123',
            'title' => 'Laravel Developer Needed',
            'status' => LeadStatus::New->value,
            'payment_verified' => true,
        ]);

        Queue::assertPushed(ScoreLeadJob::class);
    }

    public function test_duplicate_external_id_is_idempotent_and_does_not_rescore(): void
    {
        Queue::fake();

        $payload = ['id' => 'dup-1', 'title' => 'Job One'];
        $headers = ['X-Vollna-Secret' => 'test-secret'];

        $this->postJson('/api/vollna-hook', $payload, $headers)->assertStatus(201);
        $this->postJson('/api/vollna-hook', $payload, $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'duplicate');

        $this->assertDatabaseCount('leads', 1);
        Queue::assertPushed(ScoreLeadJob::class, 1);
    }

    public function test_missing_title_is_rejected(): void
    {
        $this->postJson('/api/vollna-hook', ['id' => 'no-title'], ['X-Vollna-Secret' => 'test-secret'])
            ->assertStatus(422);
    }
}
