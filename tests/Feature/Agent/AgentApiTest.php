<?php

namespace Tests\Feature\Agent;

use App\Enums\LeadStatus;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentApiTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'agent-test-token-1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('agent_api_token', self::TOKEN);
    }

    /**
     * @return array<string, string>
     */
    protected function auth(): array
    {
        return ['Authorization' => 'Bearer '.self::TOKEN];
    }

    public function test_missing_or_wrong_token_gets_json_401(): void
    {
        $this->getJson('/api/agent/leads')->assertStatus(401)->assertJson(['message' => 'Unauthenticated.']);
        $this->getJson('/api/agent/summary', ['Authorization' => 'Bearer nope'])->assertStatus(401);

        // No unauthenticated call may leave an audit row.
        $this->assertDatabaseMissing('activity_logs', ['type' => 'agent_api_call']);
    }

    public function test_leads_list_filters_orders_and_logs(): void
    {
        Lead::factory()->create(['title' => 'Old strong', 'score' => 9, 'status' => LeadStatus::Ready, 'posted_at' => now()->subDays(3)]);
        Lead::factory()->create(['title' => 'Fresh strong', 'score' => 8, 'status' => LeadStatus::Ready, 'posted_at' => now()->subHours(2)]);
        Lead::factory()->create(['title' => 'Weak', 'score' => 4, 'status' => LeadStatus::Archived, 'posted_at' => now()->subHours(1)]);

        $response = $this->getJson('/api/agent/leads?score_min=8&status=ready', $this->auth())->assertOk();

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertSame(['Fresh strong', 'Old strong'], $titles);

        $first = $response->json('data.0');
        foreach (['id', 'title', 'score', 'score_reason', 'status', 'budget', 'proposal_count', 'client_country', 'client_spend', 'client_hire_rate', 'payment_verified', 'age', 'url'] as $field) {
            $this->assertArrayHasKey($field, $first);
        }

        // posted_within_hours trims the older one.
        $this->assertCount(1, $this->getJson('/api/agent/leads?score_min=8&posted_within_hours=12', $this->auth())->json('data'));

        $this->assertDatabaseHas('activity_logs', ['type' => 'agent_api_call']);
    }

    public function test_lead_detail_includes_brief_and_proposal(): void
    {
        $lead = Lead::factory()->create(['full_brief' => 'THE FULL BRIEF', 'proposal_text' => 'THE PROPOSAL']);

        $this->getJson("/api/agent/leads/{$lead->id}", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.full_brief', 'THE FULL BRIEF')
            ->assertJsonPath('data.proposal_text', 'THE PROPOSAL');
    }

    public function test_summary_counts_and_oldest_ready(): void
    {
        // created_at pinned: the factory defaults it to the (random, past)
        // posted_at, which would break the "today" count.
        Lead::factory()->count(2)->create(['status' => LeadStatus::Ready, 'score' => 8, 'posted_at' => now()->subDay(), 'created_at' => now()]);
        Lead::factory()->create(['status' => LeadStatus::Archived, 'score' => 3, 'posted_at' => now(), 'created_at' => now()]);

        $this->getJson('/api/agent/summary', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.counts_by_status.ready', 2)
            ->assertJsonPath('data.highest_unsent_score', 8)
            ->assertJsonPath('data.new_leads_today', 3);
    }

    public function test_client_endpoint_returns_memory_and_messages(): void
    {
        $client = Client::factory()->create(['budget_discussed' => '$900 discussed']);
        Message::factory()->count(2)->create(['client_id' => $client->id]);

        $response = $this->getJson("/api/agent/clients/{$client->id}", $this->auth())->assertOk();

        $this->assertSame('$900 discussed', $response->json('data.budget_discussed'));
        $this->assertCount(2, $response->json('data.recent_messages'));
    }

    public function test_status_post_allows_journey_statuses_only(): void
    {
        $lead = Lead::factory()->create(['status' => LeadStatus::Ready]);

        $this->postJson("/api/agent/leads/{$lead->id}/status", ['status' => 'sent'], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $this->assertEquals(LeadStatus::Sent, $lead->fresh()->status);

        // The agent may never rewind a lead into the pipeline stages.
        $this->postJson("/api/agent/leads/{$lead->id}/status", ['status' => 'new'], $this->auth())
            ->assertStatus(422);
        $this->postJson("/api/agent/leads/{$lead->id}/status", ['status' => 'needs_review'], $this->auth())
            ->assertStatus(422);
    }

    public function test_token_reveal_and_regenerate_are_admin_actions(): void
    {
        $admin = User::factory()->admin()->create();
        $bidder = User::factory()->bidder()->create();

        $this->actingAs($bidder, 'sanctum')->getJson('/api/settings/agent-token')->assertStatus(403);

        $this->actingAs($admin, 'sanctum')->getJson('/api/settings/agent-token')
            ->assertOk()
            ->assertJsonPath('data.token', self::TOKEN);

        $new = $this->actingAs($admin, 'sanctum')->postJson('/api/settings/agent-token/regenerate')
            ->assertOk()
            ->json('data.token');

        $this->assertNotSame(self::TOKEN, $new);

        // Old token is dead the moment a new one exists.
        $this->getJson('/api/agent/summary', $this->auth())->assertStatus(401);
        $this->getJson('/api/agent/summary', ['Authorization' => 'Bearer '.$new])->assertOk();
    }
}
