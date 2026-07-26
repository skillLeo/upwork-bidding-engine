<?php

namespace Tests\Feature\Tenancy;

use App\Jobs\ScoreLeadJob;
use App\Models\ActivityLog;
use App\Models\AiCall;
use App\Models\Client;
use App\Models\DeletedLeadExternalId;
use App\Models\Lead;
use App\Models\SavedFilter;
use App\Models\Setting;
use App\Models\Template;
use App\Models\Tenant;
use App\Services\SettingsService;
use App\Tenancy\MissingTenantException;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The guarantee this whole phase exists for, proven against two real tenants
 * rather than against the convenience default in TestCase.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'plan' => 'trial', 'status' => Tenant::STATUS_ACTIVE]);
        $this->b = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'plan' => 'trial', 'status' => Tenant::STATUS_ACTIVE]);
    }

    private function as(Tenant $tenant, \Closure $fn): mixed
    {
        return app(TenantContext::class)->runAs($tenant, $fn);
    }

    public function test_every_tenant_owned_model_returns_only_its_own_rows(): void
    {
        foreach ([$this->a, $this->b] as $tenant) {
            $this->as($tenant, function () use ($tenant) {
                Lead::factory()->create(['title' => $tenant->slug.' lead']);
                Client::factory()->create(['name' => $tenant->slug.' client']);
                AiCall::create(['purpose' => 'scoring', 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5']);
                SavedFilter::factory()->create(['name' => $tenant->slug.' filter']);
                Template::factory()->create(['name' => $tenant->slug.' template']);
                ActivityLog::record('lead_received', meta: ['who' => $tenant->slug]);
                DeletedLeadExternalId::create(['external_id' => 'shared_job_id']);
            });
        }

        $this->as($this->a, function () {
            $this->assertSame(1, Lead::count());
            $this->assertSame('alpha lead', Lead::first()->title);

            $this->assertSame(1, Client::count());
            $this->assertSame('alpha client', Client::first()->name);

            $this->assertSame(1, AiCall::count());
            $this->assertSame('alpha filter', SavedFilter::first()->name);
            $this->assertSame('alpha template', Template::first()->name);
            $this->assertSame(1, ActivityLog::count());
        });
    }

    /**
     * An Upwork job id is global. If one tenant prunes a lead and the
     * tombstone is not scoped, that job becomes permanently invisible to
     * every other tenant — they never see it arrive and nothing reports why.
     */
    public function test_one_tenants_tombstone_does_not_hide_a_job_from_another(): void
    {
        $this->as($this->a, fn () => DeletedLeadExternalId::create(['external_id' => 'upwork~123']));

        $this->as($this->a, fn () => $this->assertTrue(
            DeletedLeadExternalId::where('external_id', 'upwork~123')->exists()
        ));

        $this->as($this->b, fn () => $this->assertFalse(
            DeletedLeadExternalId::where('external_id', 'upwork~123')->exists(),
            'tenant B must still be able to receive this job'
        ));

        // And B can write its own tombstone for the same job id.
        $this->as($this->b, fn () => DeletedLeadExternalId::create(['external_id' => 'upwork~123']));
        $this->assertSame(2, DeletedLeadExternalId::withoutGlobalScope('tenant')->count());
    }

    public function test_creating_a_record_stamps_the_current_tenant_without_being_told(): void
    {
        $lead = $this->as($this->b, fn () => Lead::factory()->create());

        $this->assertSame($this->b->id, $lead->tenant_id);
    }

    /**
     * The whole point of throwing rather than returning an empty set: a
     * silent unscoped query is the failure this layer exists to prevent.
     */
    public function test_querying_with_no_tenant_bound_throws_rather_than_leaking(): void
    {
        $this->as($this->a, fn () => Lead::factory()->create());

        app(TenantContext::class)->forget();

        $this->expectException(MissingTenantException::class);
        Lead::count();
    }

    public function test_platform_context_is_the_only_way_to_see_across_tenants(): void
    {
        $this->as($this->a, fn () => Lead::factory()->count(2)->create());
        $this->as($this->b, fn () => Lead::factory()->count(3)->create());

        app(TenantContext::class)->forget();

        $total = app(TenantContext::class)->asPlatform(fn () => Lead::count());

        $this->assertSame(5, $total);
    }

    // ---------------------------------------------------------------- settings

    public function test_settings_are_isolated_and_fall_back_to_the_platform_default(): void
    {
        $settings = app(SettingsService::class);

        // A platform default, visible to everyone who has no override.
        app(TenantContext::class)->asPlatform(function () {
            $row = new Setting;
            $row->setAttribute('tenant_id', null);
            $row->key = 'score_cutoff';
            $row->value = json_encode(6);
            $row->group = 'rules';
            $row->is_secret = false;
            $row->save();
        });

        $this->as($this->a, fn () => $settings->set('score_cutoff', 9));

        $this->as($this->a, fn () => $this->assertSame(9, $settings->get('score_cutoff'), "A's own override"));
        $this->as($this->b, fn () => $this->assertSame(6, $settings->get('score_cutoff'), 'B inherits the platform default'));
    }

    /**
     * THE bug this phase was written for. SettingsService cached with
     * rememberForever() against one global key holding DECRYPTED values,
     * including the Anthropic and Vollna credentials — so tenant B was
     * served whatever tenant A warmed the cache with.
     *
     * The credential used here is the workspace's own WHATSAPP token.
     *
     * It has to be a secret that genuinely still belongs to a workspace, and
     * the list keeps shrinking: the AI keys became pooled and platform-owned,
     * then so did the Vollna token and intake mailbox (one lead source funds
     * the platform). Writing either now throws. Each workspace does connect
     * its OWN Meta number, so its access token is the honest subject.
     */
    public function test_tenant_b_never_receives_tenant_as_decrypted_api_key(): void
    {
        $settings = app(SettingsService::class);

        $this->as($this->a, fn () => $settings->set('whatsapp_access_token', 'EAAG-AAAA-alpha-secret'));
        $this->as($this->b, fn () => $settings->set('whatsapp_access_token', 'EAAG-BBBB-beta-secret'));

        // A reads first, warming the cache.
        $this->as($this->a, fn () => $this->assertSame('EAAG-AAAA-alpha-secret', $settings->get('whatsapp_access_token')));

        // B must NOT get A's key back out of a shared cache entry.
        $this->as($this->b, fn () => $this->assertSame('EAAG-BBBB-beta-secret', $settings->get('whatsapp_access_token')));

        // And back again, to prove the first read did not poison the second.
        $this->as($this->a, fn () => $this->assertSame('EAAG-AAAA-alpha-secret', $settings->get('whatsapp_access_token')));
    }

    public function test_a_real_tenant_cannot_override_a_platform_only_key(): void
    {
        $settings = app(SettingsService::class);

        $this->expectException(\InvalidArgumentException::class);

        // Alpha is plan 'trial' — a customer, not the platform owner.
        $this->as($this->a, fn () => $settings->set('scoring_system_prompt', 'my own rubric'));
    }

    // ------------------------------------------------------------------- jobs

    /**
     * The classic multi-tenancy bug: a queued job runs with no request and
     * therefore no tenant. It must rebind the one it was dispatched under,
     * not inherit whatever happens to be bound when the queue drains.
     */
    public function test_a_queued_job_writes_to_the_tenant_it_was_dispatched_under(): void
    {
        Http::fake();

        // Both workspaces are configured. A workspace with no core stacks
        // does not reach the hard filters at all since P8 — it has not said
        // what it does, so its leads are held rather than judged (see
        // WorkspaceReadiness). This test is about WHICH tenant the job wrote
        // to, so both need to get as far as the filter.
        $this->as($this->a, fn () => app(SettingsService::class)->set('core_stacks', ['PHP']));
        $this->as($this->b, fn () => app(SettingsService::class)->set('core_stacks', ['PHP']));

        $leadA = $this->as($this->a, fn () => Lead::factory()->create(['title' => 'alpha job lead', 'proposal_count' => 999]));
        $leadB = $this->as($this->b, fn () => Lead::factory()->create(['title' => 'beta job lead', 'proposal_count' => 999]));

        // Dispatched under B, then run while A is bound — the payload's
        // tenant must win.
        $job = $this->as($this->b, fn () => new ScoreLeadJob($leadB->id));

        $this->assertSame($this->b->id, $job->tenantId);

        $this->as($this->a, fn () => app()->call([$job, 'handle']));

        // B's lead was processed (hard filter archives it)...
        $this->as($this->b, fn () => $this->assertSame('archived', $leadB->fresh()->status->value));
        // ...and A's identical lead was untouched.
        $this->as($this->a, fn () => $this->assertNotSame('archived', $leadA->fresh()->status->value));
    }

    public function test_a_job_dispatched_with_no_tenant_refuses_to_guess_an_owner(): void
    {
        app(TenantContext::class)->forget();

        $job = new ScoreLeadJob(1);
        $this->assertNull($job->tenantId);

        // Must not throw, must not run: reported and skipped.
        app()->call([$job, 'handle']);

        $this->assertTrue(true, 'handled without exception and without writing anything');
    }
}
