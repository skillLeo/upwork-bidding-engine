<?php

namespace Tests\Feature\Tenancy;

use App\Enums\LeadStatus;
use App\Jobs\ScoreLeadJob;
use App\Models\AiCall;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\SettingsService;
use App\Services\Workspaces\WorkspaceReadiness;
use App\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A brand-new workspace inherits NOTHING personal from anyone.
 *
 * THE GAP THIS CLOSES. TenantIsolationTest proves two POPULATED workspaces
 * cannot see each other's rows — a real guarantee, and it held. But it never
 * exercised the case that actually bit: an EMPTY workspace against a
 * populated one. Settings resolve tenant row → platform row → hardcoded
 * schema default, and a workspace that has entered nothing falls all the way
 * to the third layer. The founding user's own stacks, project facts,
 * signature and intake mailbox were sitting in that layer as the shipped
 * defaults, so every new workspace was born as a copy of him — scoring
 * against his definition of in-scope work, and able to claim his projects in
 * a proposal sent to a stranger.
 *
 * Isolation tests that only ever compare two configured tenants will keep
 * missing this. Hence a test written from the empty side.
 */
class NewWorkspaceInheritsNothingTest extends TestCase
{
    use RefreshDatabase;

    private function freshWorkspace(): Tenant
    {
        return Tenancy::asPlatform(fn () => Tenant::create([
            'name' => 'Northwind',
            'slug' => 'northwind',
            'plan' => 'free',
            'status' => Tenant::STATUS_ACTIVE,
        ]));
    }

    public function test_a_new_workspace_starts_with_no_stacks_facts_or_signature(): void
    {
        // The founding workspace is fully configured, exactly as production is.
        app(SettingsService::class)->setMany([
            'core_stacks' => ['PHP', 'Laravel', 'Vue'],
            'secondary_stacks' => ['Node.js'],
            'excluded_stacks' => ['Go', 'Rust'],
            'project_facts' => 'PatrolTick: guard-management SaaS. Laravel + Vue.',
            'proposal_signature' => 'Hassam',
            'gmail_address' => 'founder@skillleo.test',
        ]);

        Tenancy::runAs($this->freshWorkspace(), function () {
            $s = app(SettingsService::class);

            $this->assertSame([], $s->get('core_stacks'), 'a new workspace must not inherit anyone\'s stacks');
            $this->assertSame([], $s->get('secondary_stacks'));
            $this->assertSame([], $s->get('excluded_stacks'));
            $this->assertSame('', $s->get('project_facts'), 'a new workspace must not inherit anyone\'s track record');
            $this->assertSame('', $s->get('proposal_signature'));
            $this->assertSame('', $s->get('gmail_address'));
        });
    }

    /**
     * The credential question, asked directly. These resolve through the
     * SAME three-layer chain, so "the stacks leaked" is only reassuring if
     * the tokens provably did not.
     */
    public function test_a_new_workspace_inherits_no_credential_of_any_kind(): void
    {
        app(SettingsService::class)->setMany([
            'vollna_api_token' => 'vln-founder-token',
            'vollna_filter_id' => '99887',
            'vollna_webhook_secret' => 'whsec-founder',
            'gmail_app_password' => 'abcd efgh ijkl mnop',
            'openclaw_url' => 'https://founder-openclaw.test',
            'openclaw_token' => 'oc-founder',
            'agent_api_token' => 'agent-founder',
            'bidder_whatsapp' => '+923101111571',
            'whatsapp_access_token' => 'EAAG-founder',
            'vapid_private_key' => 'vapid-founder-private',
        ]);

        Tenancy::runAs($this->freshWorkspace(), function () {
            $s = app(SettingsService::class);

            foreach ([
                'vollna_api_token', 'vollna_filter_id', 'vollna_webhook_secret',
                'gmail_app_password', 'openclaw_url', 'openclaw_token',
                'agent_api_token', 'bidder_whatsapp', 'whatsapp_access_token',
                'vapid_private_key',
            ] as $key) {
                $this->assertEmpty(
                    $s->get($key),
                    "a new workspace resolved a value for [{$key}] — it is inheriting someone else's credential",
                );
            }
        });
    }

    public function test_no_workspace_owned_key_may_exist_on_the_platform_layer(): void
    {
        // The failure mode the three-layer chain makes possible: a row with
        // tenant_id NULL for a key that belongs to ONE workspace would be
        // served to every workspace that has not overridden it.
        $platformOnly = (array) config('tenancy.platform_only_keys');

        $illegal = Tenancy::asPlatform(fn () => Setting::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->whereNotIn('key', $platformOnly)
            ->pluck('key')
            ->all());

        $this->assertSame([], $illegal, 'these keys are workspace-owned and must never have a platform default');
    }

    // ------------------------------------------------- the readiness guard

    public function test_an_unconfigured_workspace_does_not_score_and_spends_nothing(): void
    {
        Http::fake();

        $fresh = $this->freshWorkspace();

        Tenancy::runAs($fresh, function () {
            $this->assertFalse(app(WorkspaceReadiness::class)->canScore());

            $lead = Lead::factory()->create(['status' => LeadStatus::New, 'score' => null]);

            app(ScoreLeadJob::class, ['leadId' => $lead->id]);
            ScoreLeadJob::dispatchSync($lead->id);

            $lead->refresh();

            // Still there, still visible, still unscored — nothing is lost.
            $this->assertSame(LeadStatus::New, $lead->status);
            $this->assertNull($lead->score);
            $this->assertStringContainsString('setup incomplete', strtolower((string) $lead->score_reason));
        });

        // The important half: no AI call was made, so an unconfigured
        // workspace cannot spend the platform's pooled credit.
        $this->assertSame(0, AiCall::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    public function test_the_banner_says_what_is_missing_and_clears_itself(): void
    {
        Tenancy::runAs($this->freshWorkspace(), function () {
            $readiness = app(WorkspaceReadiness::class);

            $banner = $readiness->banner();
            $this->assertNotNull($banner);
            $this->assertContains('core_stacks', array_column($banner['missing'], 'key'));
            $this->assertContains('project_facts', array_column($banner['missing'], 'key'));
            $this->assertFalse($banner['can_score']);

            // Fill in the scoring minimum only — one core stack is the whole
            // requirement (see WorkspaceReadiness for why the budget floors
            // are deliberately not on that list).
            app(SettingsService::class)->setMany(['core_stacks' => ['Figma']]);

            $this->assertTrue(app(WorkspaceReadiness::class)->canScore());
            $this->assertNotNull(app(WorkspaceReadiness::class)->banner(), 'proposals still need more');

            // Then the proposal minimum.
            app(SettingsService::class)->setMany([
                'project_facts' => 'Northwind brand system. Figma.',
                'proposal_signature' => 'Nora',
            ]);

            $this->assertNull(app(WorkspaceReadiness::class)->banner(), 'the banner clears itself');
        });
    }

    public function test_a_configured_workspace_is_unaffected_by_the_guard(): void
    {
        // The founding workspace has everything, so nothing changes for it.
        app(SettingsService::class)->setMany([
            'core_stacks' => ['PHP', 'Laravel'],
            'project_facts' => 'PatrolTick: Laravel + Vue.',
            'proposal_signature' => 'Hassam',
        ]);

        $readiness = app(WorkspaceReadiness::class);

        $this->assertTrue($readiness->canScore());
        $this->assertTrue($readiness->canWriteProposals());
        $this->assertNull($readiness->banner());
    }

    public function test_two_workspaces_pick_their_own_opposing_stacks(): void
    {
        app(SettingsService::class)->setMany(['core_stacks' => ['PHP', 'Laravel'], 'excluded_stacks' => ['MongoDB']]);

        $b = $this->freshWorkspace();

        Tenancy::runAs($b, fn () => app(SettingsService::class)->setMany([
            'core_stacks' => ['Node.js', 'MongoDB'],
            'excluded_stacks' => ['PHP'],
        ]));

        $aContext = app(SettingsService::class)->stackContext();
        $bContext = Tenancy::runAs($b, fn () => app(SettingsService::class)->stackContext());

        $this->assertStringContainsString('EXCLUDED STACKS (out of scope: a job that core-requires one of these is a low score / no-bid, and none of these may ever be claimed): MongoDB', $aContext);
        $this->assertStringContainsString('CORE STACKS (strongest fit, lead with these, may claim freely): Node.js, MongoDB', $bContext);

        $this->assertStringNotContainsString('Laravel', $bContext, "B's rubric must not mention A's stacks");
    }
}
