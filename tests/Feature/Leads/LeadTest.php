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

    public function test_default_sort_is_recently_posted(): void
    {
        $bidder = User::factory()->bidder()->create();

        // created_at (import time) deliberately reversed from posted_at (the
        // real Upwork post time) — a pass here can only mean the default is
        // genuinely keyed off posted_at, not an accidental match via
        // created_at or insertion order.
        $olderPost = Lead::factory()->create(['title' => 'older post', 'posted_at' => now()->subDays(2), 'created_at' => now()]);
        $newerPost = Lead::factory()->create(['title' => 'newer post', 'posted_at' => now()->subHours(1), 'created_at' => now()->subDay()]);

        // No sort param at all — this is what the dashboard sends by default.
        $response = $this->actingAs($bidder, 'sanctum')->getJson('/api/leads')->assertOk();

        $titles = collect($response->json('data'))->pluck('title')->all();

        $this->assertSame([$newerPost->title, $olderPost->title], $titles);
    }

    public function test_attention_sort_is_available_as_an_explicit_preset(): void
    {
        $bidder = User::factory()->bidder()->create();

        // Deliberately out of every natural order (id, created_at, score,
        // posted_at) so a pass here can only mean the compound ordering
        // itself is right, not an accidental match on a simpler one.
        $staleHighScore = Lead::factory()->create(['title' => 'stale high score', 'status' => LeadStatus::Ready, 'score' => 9, 'posted_at' => now()->subDays(3)]);
        $freshLowScore = Lead::factory()->create(['title' => 'fresh low score', 'status' => LeadStatus::Ready, 'score' => 7, 'posted_at' => now()->subMinutes(10)]);
        $freshHighScore = Lead::factory()->create(['title' => 'fresh high score', 'status' => LeadStatus::Ready, 'score' => 9, 'posted_at' => now()->subMinutes(5)]);
        $archivedHighScore = Lead::factory()->create(['title' => 'archived high score', 'status' => LeadStatus::Archived, 'score' => 10, 'posted_at' => now()]);

        $response = $this->actingAs($bidder, 'sanctum')->getJson('/api/leads?sort=-attention')->assertOk();

        $titles = collect($response->json('data'))->pluck('title')->all();

        $this->assertSame([
            $freshHighScore->title,
            $staleHighScore->title,
            $freshLowScore->title,
            $archivedHighScore->title,
        ], $titles);
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

    public function test_include_keywords_matches_title_or_brief(): void
    {
        $bidder = User::factory()->bidder()->create();
        Lead::factory()->create(['title' => 'Laravel Developer Needed', 'full_brief' => 'Build an API.']);
        Lead::factory()->create(['title' => 'Generic Job', 'full_brief' => 'We need help with Laravel setup.']);
        Lead::factory()->create(['title' => 'Unrelated', 'full_brief' => 'Nothing to see here.']);

        $response = $this->actingAs($bidder, 'sanctum')->getJson('/api/leads?include_keywords=Laravel');

        $response->assertOk();
        $this->assertEquals(2, $response->json('meta.total'));
    }

    public function test_exclude_keywords_hides_matching_leads(): void
    {
        $bidder = User::factory()->bidder()->create();
        Lead::factory()->create(['title' => 'WordPress Site Fix', 'full_brief' => 'Minor CSS tweaks.']);
        Lead::factory()->create(['title' => 'Laravel API', 'full_brief' => 'Build an API.']);

        $response = $this->actingAs($bidder, 'sanctum')->getJson('/api/leads?exclude_keywords=WordPress');

        $response->assertOk();
        $this->assertEquals(1, $response->json('meta.total'));
    }

    public function test_budget_range_filters_by_parsed_budget(): void
    {
        $bidder = User::factory()->bidder()->create();
        Lead::factory()->create(['budget' => '$500 fixed', 'budget_min' => 500, 'budget_max' => 500]);
        Lead::factory()->create(['budget' => '$5000 fixed', 'budget_min' => 5000, 'budget_max' => 5000]);
        Lead::factory()->create(['budget' => null, 'budget_min' => null, 'budget_max' => null]);

        $response = $this->actingAs($bidder, 'sanctum')->getJson('/api/leads?budget_min=1000');

        $response->assertOk();
        // The $5000 lead plus the no-budget lead (never excluded by a floor it never reported).
        $this->assertEquals(2, $response->json('meta.total'));
    }

    public function test_payment_verified_only_filters_correctly(): void
    {
        $bidder = User::factory()->bidder()->create();
        Lead::factory()->create(['payment_verified' => true]);
        Lead::factory()->create(['payment_verified' => false]);

        $response = $this->actingAs($bidder, 'sanctum')->getJson('/api/leads?payment_verified_only=1');

        $response->assertOk();
        $this->assertEquals(1, $response->json('meta.total'));
    }

    public function test_posted_within_minutes_filters_out_older_leads(): void
    {
        $bidder = User::factory()->bidder()->create();
        Lead::factory()->create(['posted_at' => now()->subMinutes(5)]);
        Lead::factory()->create(['posted_at' => now()->subHours(3)]);

        $response = $this->actingAs($bidder, 'sanctum')->getJson('/api/leads?posted_within_minutes=30');

        $response->assertOk();
        $this->assertEquals(1, $response->json('meta.total'));
    }

    public function test_search_ignores_active_filter_criteria(): void
    {
        $bidder = User::factory()->bidder()->create();
        // Wouldn't pass an "Odoo" include-keyword filter, but the search
        // term itself should still find it regardless of that filter.
        Lead::factory()->create(['title' => 'Odoo ERP Setup', 'full_brief' => 'Implement Odoo modules.']);
        Lead::factory()->create(['title' => 'Unrelated Job', 'full_brief' => 'Nothing to see here.']);

        $response = $this->actingAs($bidder, 'sanctum')->getJson(
            '/api/leads?search=Odoo&include_keywords=Laravel,PHP',
        );

        $response->assertOk();
        $this->assertEquals(1, $response->json('meta.total'));
    }

    public function test_search_result_not_matching_active_filter_is_annotated(): void
    {
        $bidder = User::factory()->bidder()->create();
        Lead::factory()->create(['title' => 'Odoo ERP Setup', 'full_brief' => 'Implement Odoo modules.']);

        $response = $this->actingAs($bidder, 'sanctum')->getJson(
            '/api/leads?search=Odoo&include_keywords=Laravel,PHP',
        );

        $response->assertOk()
            ->assertJsonPath('data.0.matches_filter', false)
            ->assertJsonPath('data.0.filter_fail_reasons.0', 'Include keywords: matches none of Laravel, PHP.');
    }

    public function test_matches_filter_is_absent_when_no_criteria_active(): void
    {
        $bidder = User::factory()->bidder()->create();
        Lead::factory()->create(['title' => 'Any Job']);

        $response = $this->actingAs($bidder, 'sanctum')->getJson('/api/leads');

        $response->assertOk();
        $this->assertArrayNotHasKey('matches_filter', $response->json('data.0'));
    }

    public function test_show_annotates_lead_when_criteria_passed(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->create(['title' => 'Odoo ERP Setup', 'full_brief' => 'Implement Odoo.']);

        $this->actingAs($bidder, 'sanctum')
            ->getJson("/api/leads/{$lead->id}?include_keywords=Laravel")
            ->assertOk()
            ->assertJsonPath('data.matches_filter', false)
            ->assertJsonPath('data.filter_fail_reasons.0', 'Include keywords: matches none of Laravel.');
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
