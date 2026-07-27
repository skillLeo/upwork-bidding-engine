<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadSourceItem;
use App\Models\Tenant;
use App\Services\Leads\LeadFanOut;
use App\Services\ScoringService;
use App\Services\SettingsService;
use App\Services\VollnaProjectImporter;
use App\Services\Workspaces\WorkspaceBootstrapper;
use App\Tenancy\Tenancy;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * One lead feed, many workspaces — the product's central promise.
 *
 * Every workspace is served the SAME jobs from one Vollna subscription. What
 * each owner controls privately is their filters, their stacks (which drive
 * both scoring and the proposal), their bidding rules, and what they have
 * done with each lead. Nothing else differs.
 *
 * Before this existed, `leads.external_id` was globally unique, so a job
 * could belong to exactly one workspace and every workspace but the first
 * was refused at the database index — silently, because intake counted the
 * failure as "skipped" and reported success.
 */
class SharedLeadPoolTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $designer;

    protected Tenant $backend;

    protected function setUp(): void
    {
        parent::setUp();

        // $this->tenant (skillleo, internal plan) is the platform's own
        // workspace and operates intake. These two are customers.
        $this->backend = Tenant::create([
            'name' => 'Backend Shop', 'slug' => 'backend-shop',
            'plan' => 'free', 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->designer = Tenant::create([
            'name' => 'Design Studio', 'slug' => 'design-studio',
            'plan' => 'free', 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->as($this->backend, fn () => app(SettingsService::class)->setMany([
            'core_stacks' => ['Laravel', 'PHP'],
            'secondary_stacks' => [],
        ]));

        $this->as($this->designer, fn () => app(SettingsService::class)->setMany([
            'core_stacks' => ['Figma', 'branding'],
            'secondary_stacks' => [],
        ]));
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $fn
     * @return T
     */
    protected function as(Tenant $tenant, \Closure $fn): mixed
    {
        return app(TenantContext::class)->runAs($tenant, $fn);
    }

    // ------------------------------------------------------------ the promise

    public function test_one_job_reaches_every_workspace(): void
    {
        Queue::fake();

        $item = LeadSourceItem::factory()->create(['external_id' => 'shared-1', 'title' => 'Laravel API']);

        $result = app(LeadFanOut::class)->distribute($item);

        $this->assertSame(3, $result['delivered'], 'every workspace should have been served');

        foreach ([$this->tenant, $this->backend, $this->designer] as $workspace) {
            $this->assertSame(
                1,
                $this->as($workspace, fn () => Lead::where('external_id', 'shared-1')->count()),
                "workspace [{$workspace->slug}] did not receive the lead",
            );
        }
    }

    public function test_the_same_job_can_exist_in_two_workspaces_at_once(): void
    {
        // The regression this whole feature exists for: a global unique on
        // leads.external_id made this insert impossible, which is why three
        // production workspaces sat at zero leads while the founding one
        // held 148.
        Queue::fake();

        $item = LeadSourceItem::factory()->create(['external_id' => 'collide-1']);

        app(LeadFanOut::class)->distribute($item);

        $this->assertSame(3, Tenancy::asPlatform(fn () => Lead::where('external_id', 'collide-1')->count()));
    }

    public function test_what_one_workspace_does_with_a_lead_is_invisible_to_the_others(): void
    {
        Queue::fake();

        $item = LeadSourceItem::factory()->create(['external_id' => 'private-state']);
        app(LeadFanOut::class)->distribute($item);

        $this->as($this->backend, function () {
            Lead::where('external_id', 'private-state')->first()->update([
                'status' => LeadStatus::Won,
                'is_favorite' => true,
                'score' => 9,
                'proposal_text' => 'Backend Shop wrote this',
            ]);
        });

        $theirs = $this->as($this->designer, fn () => Lead::where('external_id', 'private-state')->first());

        $this->assertSame(LeadStatus::New, $theirs->status, 'a status change must not cross workspaces');
        $this->assertFalse((bool) $theirs->is_favorite, 'a favourite must not cross workspaces');
        $this->assertNull($theirs->score, 'a score must not cross workspaces');
        $this->assertNull($theirs->proposal_text, 'a proposal must not cross workspaces');
    }

    public function test_each_workspace_decides_for_itself_what_is_worth_scoring(): void
    {
        $laravelJob = LeadSourceItem::factory()->create([
            'external_id' => 'laravel-job',
            'title' => 'Laravel developer for an API',
            'full_brief' => 'We need a Laravel backend.',
            'skills' => ['Laravel', 'PHP'],
        ]);

        Queue::fake();
        app(LeadFanOut::class)->distribute($laravelJob);

        $verdict = fn (Tenant $t) => $this->as($t, function () {
            $lead = Lead::where('external_id', 'laravel-job')->first();

            return app(ScoringService::class)->stackRelevance($lead)['relevant'];
        });

        $this->assertTrue($verdict($this->backend), 'the backend shop should pay to score a Laravel job');
        $this->assertFalse($verdict($this->designer), 'the design studio should not pay to score a Laravel job');
    }

    public function test_a_lead_outside_a_workspaces_stacks_stays_visible_and_unarchived(): void
    {
        // The owner asked for this explicitly: nothing is hidden or archived
        // on their behalf. The stack gate decides where the AI budget goes,
        // never what appears on the board — filtering is the workspace's own
        // business, done with its own filters and its own manual archiving.
        $item = LeadSourceItem::factory()->create([
            'external_id' => 'out-of-stack',
            'title' => 'Laravel developer',
            'full_brief' => 'Laravel work.',
            'skills' => ['Laravel'],
        ]);

        app(LeadFanOut::class)->distribute($item, collect([$this->designer]));

        $lead = $this->as($this->designer, fn () => Lead::where('external_id', 'out-of-stack')->first());

        $this->assertNotNull($lead, 'the lead must still arrive');
        $this->assertSame(LeadStatus::New, $lead->status, 'it must not be archived');
        $this->assertStringContainsString('still here', (string) $lead->score_reason);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ intake

    public function test_intake_polls_once_and_stores_one_pool_row_for_every_workspace(): void
    {
        Queue::fake();

        $result = app(VollnaProjectImporter::class)->ingest([
            'title' => 'Laravel Developer Needed',
            'description' => 'Build an API.',
            'url' => 'https://www.vollna.com/go?pid=9001',
            'published' => now()->toIso8601String(),
        ]);

        $this->assertSame('accepted', $result['status']);
        $this->assertSame(3, $result['delivered']);

        // ONE pool row, three workspace copies. That ratio is the whole
        // point: the job's text is stored per workspace, but the source is
        // read from Vollna exactly once.
        $this->assertSame(1, LeadSourceItem::count());
        $this->assertSame(3, Tenancy::asPlatform(fn () => Lead::count()));
    }

    public function test_a_redelivered_job_is_not_distributed_twice(): void
    {
        Queue::fake();

        $project = [
            'title' => 'Laravel Developer Needed',
            'description' => 'Build an API.',
            'url' => 'https://www.vollna.com/go?pid=9002',
            'published' => now()->toIso8601String(),
        ];

        app(VollnaProjectImporter::class)->ingest($project);
        $second = app(VollnaProjectImporter::class)->ingest($project);

        $this->assertSame('duplicate', $second['status']);
        $this->assertSame(1, LeadSourceItem::count());
        $this->assertSame(3, Tenancy::asPlatform(fn () => Lead::count()));
    }

    public function test_a_workspace_that_deleted_a_job_never_gets_it_back(): void
    {
        Queue::fake();

        $item = LeadSourceItem::factory()->create(['external_id' => 'tombstoned']);

        $this->as($this->designer, fn () => \App\Models\DeletedLeadExternalId::create(['external_id' => 'tombstoned']));

        app(LeadFanOut::class)->distribute($item);

        $this->assertSame(0, $this->as($this->designer, fn () => Lead::where('external_id', 'tombstoned')->count()));
        $this->assertSame(1, $this->as($this->backend, fn () => Lead::where('external_id', 'tombstoned')->count()));
    }

    // ------------------------------------------------------------ new workspace

    public function test_a_new_workspace_is_backfilled_from_the_pool(): void
    {
        Queue::fake();

        LeadSourceItem::factory()->count(4)->create(['posted_at' => now()->subDay()]);
        LeadSourceItem::factory()->create(['posted_at' => now()->subDays(40)]);

        $newcomer = Tenant::create([
            'name' => 'Newcomer', 'slug' => 'newcomer',
            'plan' => 'free', 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $result = app(LeadFanOut::class)->backfillWorkspace($newcomer, 7);

        $this->assertSame(4, $result['delivered']);
        $this->assertSame(4, $this->as($newcomer, fn () => Lead::count()), 'the 40-day-old job is dead inventory');
    }

    public function test_a_specialization_preset_seeds_the_workspaces_own_stacks(): void
    {
        $newcomer = Tenant::create([
            'name' => 'Studio', 'slug' => 'studio',
            'plan' => 'free', 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->assertTrue(app(WorkspaceBootstrapper::class)->applyPreset($newcomer, 'graphic-design'));

        $stacks = $this->as($newcomer, fn () => app(SettingsService::class)->get('core_stacks'));

        $this->assertContains('figma', array_map('strtolower', (array) $stacks));
        $this->assertSame('Graphic design & branding', $newcomer->refresh()->specialization);
    }

    public function test_a_preset_never_overwrites_stacks_somebody_already_chose(): void
    {
        $this->assertFalse(
            app(WorkspaceBootstrapper::class)->applyPreset($this->backend, 'graphic-design'),
            'the backend shop already said what it builds',
        );

        $this->assertSame(
            ['Laravel', 'PHP'],
            $this->as($this->backend, fn () => app(SettingsService::class)->get('core_stacks')),
        );
    }

    // ------------------------------------------------------------ the trap

    public function test_running_as_a_workspace_inside_platform_context_is_still_scoped(): void
    {
        // The bug that made the first fan-out a silent no-op. LeadFanOut
        // reads the pool as platform and then delivers to one workspace at a
        // time; because runAs() left platform mode ON, its "does this
        // workspace already have this job?" check answered across ALL
        // workspaces, and every workspace was told it already had
        // everything.
        Queue::fake();

        $item = LeadSourceItem::factory()->create(['external_id' => 'scope-trap']);
        app(LeadFanOut::class)->distribute($item, collect([$this->backend]));

        $seenByDesigner = Tenancy::asPlatform(
            fn () => $this->as($this->designer, fn () => Lead::where('external_id', 'scope-trap')->count())
        );

        $this->assertSame(0, $seenByDesigner, 'runAs() inside asPlatform() must restore tenant scoping');
    }
}
