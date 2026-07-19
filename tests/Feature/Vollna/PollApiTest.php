<?php

namespace Tests\Feature\Vollna;

use App\Console\Commands\VollnaPollApiCommand;
use App\Models\Lead;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PollApiTest extends TestCase
{
    use RefreshDatabase;

    protected SettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(SettingsService::class);
        $this->settings->setMany([
            'vollna_api_token' => 'test-api-token',
            'vollna_filter_id' => '40694',
            'anthropic_api_key' => 'sk-ant-test',
            'scoring_system_prompt' => 'THE RUBRIC',
            'proposal_skill' => 'THE GUIDE',
            'proposal_quality_gate' => false,
            'bidder_whatsapp' => '+15550001111',
            'min_budget' => 0,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $projects
     * @return array<string, mixed>
     */
    protected function apiResponse(array $projects): array
    {
        return ['data' => $projects];
    }

    /**
     * @return array<string, mixed>
     */
    protected function project(string $pid, string $title): array
    {
        return [
            'title' => $title,
            'description' => 'A real brief with enough detail to score.',
            'url' => "https://www.vollna.com/go?pid={$pid}&url=https%3A%2F%2Fwww.upwork.com%2Fjobs%2F~01",
            'publishedAt' => now()->subMinutes(5)->toIso8601String(),
            'skills' => ['Laravel', 'PHP'],
            'budget' => ['type' => 'FIXED', 'amount' => '500'],
            'connectsRequired' => 6,
            'clientDetails' => [
                'country' => 'United States',
                'totalSpent' => '3.3k',
                'hireRate' => 1,
                'paymentMethodVerified' => true,
            ],
        ];
    }

    public function test_poll_imports_new_projects_and_stamps_intake(): void
    {
        Http::fake([
            'api.vollna.com/*' => Http::response($this->apiResponse([
                $this->project('111', 'Laravel API Developer'),
            ])),
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => '{"score": 3, "bid": false, "reason": "weak"}']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
            ]),
        ]);

        $this->artisan('vollna:poll-api')->assertSuccessful();

        $this->assertDatabaseHas('leads', ['external_id' => 'vollna_pid_111', 'title' => 'Laravel API Developer']);
        $this->assertNotNull($this->settings->get('vollna_last_intake_at'));

        // No `limit` param — it is exactly what Vollna rejects with a 400.
        Http::assertSent(function ($request) {
            return str_contains((string) $request->url(), 'api.vollna.com')
                && ! str_contains((string) $request->url(), 'limit');
        });
    }

    public function test_poll_is_additive_and_never_deletes_unseen_leads(): void
    {
        // The whole reason this command exists instead of scheduling
        // VollnaSyncJob: the API returns only the newest page, so a
        // mirroring sync would wipe the back catalogue every 2 minutes.
        $survivor = Lead::factory()->create(['external_id' => 'vollna_pid_OLD', 'title' => 'Older lead']);

        Http::fake([
            'api.vollna.com/*' => Http::response($this->apiResponse([
                $this->project('222', 'Brand new job'),
            ])),
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => '{"score": 2, "bid": false, "reason": "no"}']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
            ]),
        ]);

        $this->artisan('vollna:poll-api')->assertSuccessful();

        $this->assertDatabaseHas('leads', ['id' => $survivor->id]);
        $this->assertDatabaseHas('leads', ['external_id' => 'vollna_pid_222']);
    }

    public function test_repeat_poll_dedupes_without_rescoring(): void
    {
        Http::fake([
            'api.vollna.com/*' => Http::response($this->apiResponse([
                $this->project('333', 'Repeated job'),
            ])),
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => '{"score": 2, "bid": false, "reason": "no"}']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
            ]),
        ]);

        $this->artisan('vollna:poll-api')->assertSuccessful();
        $aiCallsAfterFirst = \App\Models\AiCall::count();

        $this->artisan('vollna:poll-api')->assertSuccessful();

        $this->assertSame(1, Lead::where('external_id', 'vollna_pid_333')->count());
        $this->assertSame($aiCallsAfterFirst, \App\Models\AiCall::count(), 'A duplicate must never pay for a second scoring call.');
    }

    public function test_poll_queues_scoring_instead_of_running_it_inline(): void
    {
        // The poller runs INSIDE the scheduler process (proc_open is
        // disabled on the host), so it must return in seconds: scoring is
        // queued for the scheduler's queue closure, never run in-line.
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\ScoreLeadJob::class]);

        Http::fake([
            'api.vollna.com/*' => Http::response($this->apiResponse([
                $this->project('444', 'Queued scoring job'),
            ])),
        ]);

        $this->artisan('vollna:poll-api')->assertSuccessful();

        $lead = Lead::where('external_id', 'vollna_pid_444')->firstOrFail();
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\ScoreLeadJob::class, fn ($job) => $job->leadId === $lead->id);

        // No AI call happened during the poll itself.
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'api.anthropic.com'));
    }

    public function test_projects_older_than_the_scoring_window_are_never_imported(): void
    {
        // The resurrection bug: a mirror sync re-added 515 deleted
        // two-week-old leads. Old postings now never get a row at all.
        $old = $this->project('555', 'Ancient job');
        $old['publishedAt'] = now()->subDays(10)->toIso8601String();

        Http::fake(['api.vollna.com/*' => Http::response($this->apiResponse([$old]))]);

        $this->artisan('vollna:poll-api')->assertSuccessful();

        $this->assertDatabaseMissing('leads', ['external_id' => 'vollna_pid_555']);
    }

    public function test_api_failure_alerts_once_per_incident(): void
    {
        Http::fake([
            'api.vollna.com/*' => Http::response(['error' => 'Invalid token'], 401),
            'openclaw.test/*' => Http::response(['success' => true]),
        ]);

        $this->settings->setMany(['openclaw_url' => 'https://openclaw.test', 'openclaw_token' => 'tok']);

        $this->artisan('vollna:poll-api')->assertFailed();
        $this->assertTrue(Cache::has(VollnaPollApiCommand::FAIL_ALERT_KEY));
        $this->assertDatabaseHas('activity_logs', ['type' => 'vollna_poll_failed']);

        $sentAfterFirst = count(Http::recorded(fn ($r) => str_contains((string) $r->url(), 'openclaw.test')));

        // Running every 2 minutes, an outage must not become a flood.
        $this->artisan('vollna:poll-api')->assertFailed();
        $this->assertSame(
            $sentAfterFirst,
            count(Http::recorded(fn ($r) => str_contains((string) $r->url(), 'openclaw.test'))),
            'Second failure must not send another alert.',
        );
    }

    public function test_silence_switch_watches_intake_from_either_door(): void
    {
        $this->travelTo(now('Asia/Karachi')->setTime(12, 0));
        Http::fake(['*' => Http::response(['success' => true])]);

        // Webhook long dead, but the API poller just succeeded — no alarm.
        $this->settings->set('vollna_last_webhook_at', now()->subDays(3)->toIso8601String());
        $this->settings->set('vollna_last_intake_at', now()->subMinutes(2)->toIso8601String());

        $this->artisan('vollna:check-silence')->assertSuccessful();
        Http::assertNothingSent();
    }
}
