<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NlSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_restricts_results_using_parsed_criteria(): void
    {
        $bidder = User::factory()->bidder()->create();
        Lead::factory()->create(['title' => 'Laravel API Build', 'full_brief' => 'x', 'budget_min' => 800, 'budget_max' => 800]);
        Lead::factory()->create(['title' => 'Laravel API Build', 'full_brief' => 'x', 'budget_min' => 100, 'budget_max' => 100]);
        Lead::factory()->create(['title' => 'React Dashboard', 'full_brief' => 'x', 'budget_min' => 900, 'budget_max' => 900]);

        // AND, not OR: must match the stack keyword AND the budget floor.
        $response = $this->actingAs($bidder, 'sanctum')->getJson('/api/leads?search='.urlencode('laravel over $500'));

        $response->assertOk();
        $this->assertEquals(1, $response->json('meta.total'));
        $this->assertSame('Laravel API Build', $response->json('data.0.title'));
    }

    public function test_search_does_not_narrow_within_the_active_saved_filter(): void
    {
        $bidder = User::factory()->bidder()->create();

        $odooLead = Lead::factory()->create(['title' => 'Odoo Customization', 'full_brief' => 'x']);
        Lead::factory()->create(['title' => 'Laravel Fix', 'full_brief' => 'x']);

        // include_keywords=Laravel is what the frontend sends for whichever
        // saved filter is currently active - searching "odoo" against it
        // must still surface the odoo lead (flagged, not hidden), per the
        // explicit correction that search must search ALL leads, not
        // narrow within the active filter.
        $response = $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/leads?include_keywords=Laravel&search=odoo');

        $response->assertOk();
        $this->assertEquals(1, $response->json('meta.total'));
        $this->assertSame($odooLead->id, $response->json('data.0.id'));
        $this->assertFalse($response->json('data.0.matches_filter'));
        $this->assertNotEmpty($response->json('data.0.filter_fail_reasons'));
    }

    public function test_hire_rate_and_budget_type_filter_the_query(): void
    {
        $bidder = User::factory()->bidder()->create();
        Lead::factory()->create(['title' => 'A', 'full_brief' => 'x', 'client_hire_rate_pct' => 80, 'budget_type' => 'fixed']);
        Lead::factory()->create(['title' => 'B', 'full_brief' => 'x', 'client_hire_rate_pct' => 20, 'budget_type' => 'fixed']);
        Lead::factory()->create(['title' => 'C', 'full_brief' => 'x', 'client_hire_rate_pct' => 90, 'budget_type' => 'hourly']);

        $response = $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/leads?search='.urlencode('high hire rate fixed price'));

        $response->assertOk();
        $this->assertEquals(1, $response->json('meta.total'));
        $this->assertSame('A', $response->json('data.0.title'));
    }

    public function test_no_competition_filters_by_proposal_count(): void
    {
        $bidder = User::factory()->bidder()->create();
        Lead::factory()->create(['title' => 'Low competition', 'full_brief' => 'x', 'proposal_count' => 2]);
        Lead::factory()->create(['title' => 'High competition', 'full_brief' => 'x', 'proposal_count' => 20]);

        $response = $this->actingAs($bidder, 'sanctum')->getJson('/api/leads?search='.urlencode('no competition'));

        $response->assertOk();
        $this->assertEquals(1, $response->json('meta.total'));
        $this->assertSame('Low competition', $response->json('data.0.title'));
    }

    public function test_unparseable_search_falls_back_to_plain_keyword_match(): void
    {
        $bidder = User::factory()->bidder()->create();
        Lead::factory()->create(['title' => 'Zzyx Marker Title', 'full_brief' => 'x']);
        Lead::factory()->create(['title' => 'Unrelated', 'full_brief' => 'x']);

        $response = $this->actingAs($bidder, 'sanctum')->getJson('/api/leads?search=Zzyx+Marker');

        $response->assertOk();
        $this->assertEquals(1, $response->json('meta.total'));
    }

    public function test_search_response_includes_criteria_chips(): void
    {
        $bidder = User::factory()->bidder()->create();
        Lead::factory()->create(['title' => 'Laravel Job', 'full_brief' => 'x']);

        $response = $this->actingAs($bidder, 'sanctum')->getJson('/api/leads?search='.urlencode('laravel over $500'));

        $response->assertOk();
        $labels = array_column($response->json('meta.search_chips'), 'label');
        $this->assertContains('Laravel', $labels);
        $this->assertContains('budget > $500', $labels);
    }
}
