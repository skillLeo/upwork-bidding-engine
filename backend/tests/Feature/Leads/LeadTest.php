<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_leads(): void
    {
        $this->getJson('/api/leads')->assertStatus(401);
    }

    public function test_bidder_can_list_and_filter_leads_by_status(): void
    {
        $bidder = User::factory()->bidder()->create();
        Lead::factory()->count(3)->ready(9)->create();
        Lead::factory()->count(2)->create();

        $response = $this->actingAs($bidder, 'sanctum')->getJson('/api/leads?status=ready');

        $response->assertOk();
        $this->assertEquals(3, $response->json('meta.total'));
    }

    public function test_search_matches_title(): void
    {
        $bidder = User::factory()->bidder()->create();
        Lead::factory()->create(['title' => 'Very Unique Searchable Title']);
        Lead::factory()->count(3)->create();

        $response = $this->actingAs($bidder, 'sanctum')->getJson('/api/leads?search=Unique+Searchable');

        $response->assertOk();
        $this->assertEquals(1, $response->json('meta.total'));
    }

    public function test_show_returns_full_lead(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->ready(8)->create();

        $this->actingAs($bidder, 'sanctum')
            ->getJson("/api/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $lead->id)
            ->assertJsonPath('data.score', 8);
    }

    public function test_bidder_can_mark_lead_sent_and_a_client_is_provisioned(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->ready(8)->create();

        $response = $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/status", ['status' => 'sent']);

        $response->assertOk()->assertJsonPath('data.status', 'sent');

        $lead->refresh();
        $this->assertEquals(LeadStatus::Sent, $lead->status);
        $this->assertNotNull($lead->client_id);
        $this->assertDatabaseHas('clients', ['id' => $lead->client_id, 'lead_id' => $lead->id]);
    }

    public function test_status_update_rejects_system_controlled_target_status(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->create();

        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/status", ['status' => 'ready'])
            ->assertStatus(422);
    }

    public function test_only_admin_can_rescore(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->ready()->create();

        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/rescore")
            ->assertStatus(403);

        Queue::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/rescore")
            ->assertOk()
            ->assertJsonPath('data.status', 'new');

        $lead->refresh();
        $this->assertNull($lead->score);
    }
}
