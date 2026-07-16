<?php

namespace Tests\Feature\Webhooks;

use App\Enums\LeadStatus;
use App\Jobs\ScoreLeadJob;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        // Scoring now runs inline on the webhook request, so this must fake
        // the outbound OpenClaw call rather than the queue.
        Http::fake();

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

    public function test_accepts_valid_payload_creates_lead_and_scores_it_inline(): void
    {
        app(SettingsService::class)->setMany(['openclaw_url' => 'https://openclaw.test', 'openclaw_token' => 'token']);
        Http::fake(['openclaw.test/*' => Http::response(['score' => 9, 'reason' => 'Great fit', 'proposal' => 'Hi there...'])]);

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

        // No queue/cron involved: by the time the webhook responds, the
        // lead is already scored and past `new`/`scoring`.
        $this->assertDatabaseHas('leads', [
            'external_id' => 'vollna_pid_3359',
            'title' => 'Laravel Developer Needed',
            'status' => LeadStatus::Ready->value,
            'score' => 9,
            'payment_verified' => true,
            'client_country' => 'United States',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/task') && $request->data()['skill'] === 'score_and_write');
    }

    public function test_batch_with_multiple_projects_creates_a_lead_per_project(): void
    {
        app(SettingsService::class)->setMany(['openclaw_url' => 'https://openclaw.test', 'openclaw_token' => 'token']);
        Http::fake(['openclaw.test/*' => Http::response(['score' => 3, 'reason' => 'Weak fit', 'proposal' => ''])]);

        $response = $this->postJson('/api/vollna-hook', $this->batch(
            ['title' => 'Job One', 'url' => 'https://www.vollna.com/go?pid=101'],
            ['title' => 'Job Two', 'url' => 'https://www.vollna.com/go?pid=102'],
        ), ['X-Vollna-Secret' => 'test-secret']);

        $response->assertStatus(201)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.accepted', 2);

        $this->assertDatabaseCount('leads', 2);
        // 2 calls per lead: the isReachable() health check, then the real
        // score_and_write call.
        Http::assertSentCount(4);
    }

    public function test_duplicate_external_id_is_idempotent_and_does_not_rescore(): void
    {
        app(SettingsService::class)->setMany(['openclaw_url' => 'https://openclaw.test', 'openclaw_token' => 'token']);
        Http::fake(['openclaw.test/*' => Http::response(['score' => 3, 'reason' => 'Weak fit', 'proposal' => ''])]);

        $payload = $this->batch(['title' => 'Job One', 'url' => 'https://www.vollna.com/go?pid=dup-1']);
        $headers = ['X-Vollna-Secret' => 'test-secret'];

        $this->postJson('/api/vollna-hook', $payload, $headers)->assertStatus(201);
        $this->postJson('/api/vollna-hook', $payload, $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.duplicate', 1);

        $this->assertDatabaseCount('leads', 1);
        // Health check + score_and_write for the first delivery; the
        // duplicate delivery makes neither call.
        Http::assertSentCount(2);
    }

    public function test_unreachable_openclaw_queues_instead_of_scoring_inline(): void
    {
        // OpenClaw runs on a machine that isn't always on - the health
        // check must fail fast and the lead must still save, still
        // respond 201, and stay `new` with a real queued retry (not lost,
        // not hung) instead of an inline scoring attempt. Queue::fake()
        // isolates "was it queued" from "what happens when it runs" -
        // the queue driver itself is covered elsewhere.
        Queue::fake();
        app(SettingsService::class)->setMany(['openclaw_url' => 'https://openclaw.test', 'openclaw_token' => 'token']);
        Http::fake(['openclaw.test/health' => Http::response(null, 500)]);

        $response = $this->postJson('/api/vollna-hook', $this->batch([
            'title' => 'Offline Test Job',
            'url' => 'https://www.vollna.com/go?pid=offline-1',
        ]), ['X-Vollna-Secret' => 'test-secret']);

        $response->assertStatus(201)->assertJsonPath('data.accepted', 1);

        $this->assertDatabaseHas('leads', [
            'external_id' => 'vollna_pid_offline-1',
            'status' => LeadStatus::New->value,
            'score' => null,
        ]);

        // Only the health check fires - never an inline scoring call.
        Http::assertSentCount(1);
        Queue::assertPushed(ScoreLeadJob::class);
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
