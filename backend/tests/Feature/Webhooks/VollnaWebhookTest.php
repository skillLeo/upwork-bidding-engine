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

    /**
     * @return array<string, mixed>
     */
    protected function batch(array ...$projects): array
    {
        return [
            'total' => count($projects),
            'results_url' => 'https://www.vollna.com/dashboard/monitoring/result/1',
            'filter' => ['id' => 1, 'name' => 'Backend Development'],
            'filters' => [['id' => 1, 'name' => 'Backend Development']],
            'projects' => $projects,
        ];
    }

    public function test_rejects_request_without_a_secret_header(): void
    {
        $this->postJson('/api/vollna-hook', $this->batch(['title' => 'Job', 'url' => '...?pid=1']))
            ->assertStatus(401);

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_rejects_request_with_wrong_secret(): void
    {
        $this->postJson(
            '/api/vollna-hook',
            $this->batch(['title' => 'Job', 'url' => '...?pid=1']),
            ['X-Vollna-Secret' => 'wrong-secret'],
        )->assertStatus(401);

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_accepts_bearer_token_auth(): void
    {
        // Vollna's own webhook UI only offers None / Bearer Token / Basic Auth -
        // Bearer is the primary real-world path, X-Vollna-Secret is a fallback.
        Queue::fake();

        $response = $this->postJson(
            '/api/vollna-hook',
            $this->batch(['title' => 'Bearer Auth Job', 'url' => 'https://www.vollna.com/go?pid=bearer-job-1']),
            ['Authorization' => 'Bearer test-secret'],
        );

        $response->assertStatus(201)->assertJsonPath('data.accepted', 1);
        $this->assertDatabaseHas('leads', ['external_id' => 'vollna_pid_bearer-job-1']);
    }

    public function test_rejects_wrong_bearer_token(): void
    {
        $this->postJson(
            '/api/vollna-hook',
            $this->batch(['title' => 'Job', 'url' => '...?pid=1']),
            ['Authorization' => 'Bearer wrong-secret'],
        )->assertStatus(401);
    }

    public function test_accepts_valid_payload_creates_lead_and_dispatches_scoring(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/vollna-hook', $this->batch([
            'url' => 'https://www.vollna.com/go?module=webhook&pid=3359&url=https%3A%2F%2Fwww.upwork.com%2Fjobs%2F~01',
            'title' => 'Laravel Developer Needed',
            'description' => 'Build an API.',
            'budget_type' => 'fixed',
            'budget' => '500 USD',
            'published' => '2026-07-12T10:00:00+00:00',
            'client_details' => [
                'country' => ['name' => 'United States', 'iso_code2' => 'US'],
                'total_spent' => 12500,
                'payment_method_verified' => true,
            ],
        ]), ['X-Vollna-Secret' => 'test-secret']);

        $response->assertStatus(201)->assertJsonPath('data.accepted', 1);

        $this->assertDatabaseHas('leads', [
            'external_id' => 'vollna_pid_3359',
            'title' => 'Laravel Developer Needed',
            'status' => LeadStatus::New->value,
            'payment_verified' => true,
            'client_country' => 'United States',
        ]);

        Queue::assertPushed(ScoreLeadJob::class);
    }

    public function test_batch_with_multiple_projects_creates_a_lead_per_project(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/vollna-hook', $this->batch(
            ['title' => 'Job One', 'url' => 'https://www.vollna.com/go?pid=101'],
            ['title' => 'Job Two', 'url' => 'https://www.vollna.com/go?pid=102'],
        ), ['X-Vollna-Secret' => 'test-secret']);

        $response->assertStatus(201)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.accepted', 2);

        $this->assertDatabaseCount('leads', 2);
        Queue::assertPushed(ScoreLeadJob::class, 2);
    }

    public function test_duplicate_external_id_is_idempotent_and_does_not_rescore(): void
    {
        Queue::fake();

        $payload = $this->batch(['title' => 'Job One', 'url' => 'https://www.vollna.com/go?pid=dup-1']);
        $headers = ['X-Vollna-Secret' => 'test-secret'];

        $this->postJson('/api/vollna-hook', $payload, $headers)->assertStatus(201);
        $this->postJson('/api/vollna-hook', $payload, $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.duplicate', 1);

        $this->assertDatabaseCount('leads', 1);
        Queue::assertPushed(ScoreLeadJob::class, 1);
    }

    public function test_missing_title_is_rejected(): void
    {
        $this->postJson(
            '/api/vollna-hook',
            $this->batch(['url' => 'https://www.vollna.com/go?pid=no-title']),
            ['X-Vollna-Secret' => 'test-secret'],
        )->assertStatus(422);
    }
}
