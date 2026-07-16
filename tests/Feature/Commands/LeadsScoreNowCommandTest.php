<?php

namespace Tests\Feature\Commands;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LeadsScoreNowCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->setMany([
            'openclaw_url' => 'https://openclaw.test',
            'openclaw_token' => 'token',
            'score_cutoff' => 7,
        ]);
    }

    public function test_scores_a_lead_and_saves_the_result(): void
    {
        Http::fake(['openclaw.test/*' => Http::response(['score' => 9, 'reason' => 'Great fit', 'proposal' => 'Hi...'])]);

        $lead = Lead::factory()->create([
            'proposal_count' => 2,
            'budget' => '$500 fixed',
            'status' => LeadStatus::New,
            'posted_at' => now(),
        ]);

        $this->artisan('leads:score-now', ['id' => $lead->id])
            ->assertSuccessful();

        $lead->refresh();
        $this->assertEquals(9, $lead->score);
        $this->assertEquals(LeadStatus::Ready, $lead->status);
    }

    public function test_hard_filter_failure_does_not_call_openclaw(): void
    {
        Http::fake();

        $lead = Lead::factory()->create(['proposal_count' => 999, 'status' => LeadStatus::New]);

        $this->artisan('leads:score-now', ['id' => $lead->id])
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull($lead->fresh()->score);
    }

    public function test_missing_lead_fails_cleanly(): void
    {
        $this->artisan('leads:score-now', ['id' => 999999])
            ->assertFailed();
    }
}
