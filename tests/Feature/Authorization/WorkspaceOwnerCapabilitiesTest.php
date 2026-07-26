<?php

namespace Tests\Feature\Authorization;

use App\Authorization\RoleProvisioner;
use App\Authorization\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\Workspaces\WorkspaceReadiness;
use App\Tenancy\Tenancy;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantTeamResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Exactly what an "admin" — a workspace owner — can and cannot do.
 *
 * The line is not about seniority, it is about OWNERSHIP. Everything that
 * describes how this workspace bids is theirs: bidding rules, stacks,
 * notification thresholds, quiet hours, project facts, signature, their own
 * Vollna credentials, their members, their filters, their diagnostics.
 *
 * What is not theirs is the PRODUCT: the scoring methodology, the drafting
 * skill, the pooled AI keys and model choices, the mail transport, the
 * signup mode. Those are identical for every workspace by design — a
 * workspace that could fork the rubric would be scored by a different
 * product than the one it bought.
 *
 * Written as one table rather than scattered assertions because "what can an
 * admin do" is a question that gets asked repeatedly, and a list that
 * answers it is worth more than the sum of its cases.
 */
class WorkspaceOwnerCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $workspace;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // A genuinely new, customer-plan workspace — NOT the founding
        // 'internal' one, whose platform-owning status exempts it from the
        // platform-only rules and would hide every interesting failure.
        $this->workspace = Tenancy::asPlatform(fn () => Tenant::create([
            'name' => 'Northwind', 'slug' => 'northwind',
            'plan' => 'free', 'status' => Tenant::STATUS_ACTIVE,
        ]));

        app(RoleProvisioner::class)->provision($this->workspace);

        $this->admin = User::factory()->create(['email' => 'nora@northwind.test']);
        $this->workspace->users()->syncWithoutDetaching([$this->admin->id => ['joined_at' => now()]]);
        $this->workspace->update(['owner_user_id' => $this->admin->id]);

        TenantTeamResolver::pinnedTo(
            $this->workspace->id,
            fn () => $this->admin->syncRoles([TenantRole::Owner->value]),
        );

        app(TenantContext::class)->set($this->workspace);

        // Clear the Spatie team override before any request runs.
        //
        // Provisioning pins a team and restores whatever it found, and what
        // it found here was the founding workspace bound by TestCase::setUp.
        // A real HTTP request never sees this — each one boots a fresh
        // container — but in a test the container persists, so an override
        // left pointing at workspace 1 would make every permission check
        // below look in the wrong workspace and 403 for a reason that has
        // nothing to do with what is being tested. Null clears it, so the
        // resolver falls back to the tenant the request binds from its host.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        // ResolveTenant binds the tenant from the HOST and nothing else — a
        // tenant id taken from a header or query string would be horizontal
        // privilege escalation. Laravel's test client builds its request from
        // a relative URI, so the host is always the central domain and the
        // resolver takes its configured FALLBACK. Point that fallback at this
        // workspace and the request binds it exactly as a real subdomain
        // would, without weakening the rule being relied on.
        config(['tenancy.default_tenant_slug' => $this->workspace->slug]);
    }

    private function asAdmin(): TestResponse|static
    {
        Auth::forgetGuards();

        return $this->withToken(
            Tenancy::runAs($this->workspace, fn () => $this->admin->createToken('t')->plainTextToken)
        );
    }

    /**
     * Everything that describes how THIS workspace bids belongs to its owner.
     */
    public function test_an_admin_runs_every_part_of_their_own_workspace(): void
    {
        $theirs = [
            'bidding rules' => ['min_budget' => 300, 'score_cutoff' => 8, 'max_proposals' => 30],
            'their stacks' => ['core_stacks' => ['Figma'], 'excluded_stacks' => ['PHP']],
            'notification rules' => ['notify_score_min' => 9, 'notification_freshness_hours' => 24],
            'quiet hours' => ['quiet_hours_start' => 22, 'quiet_hours_end' => 8],
            'alert mode' => ['whatsapp_alert_mode' => 'paused'],
            // The four things only this workspace can supply. They live on
            // their own Settings tab precisely because the methodology tab
            // beside them is platform-owned — hiding the methodology must not
            // also hide a workspace's own facts, or its proposals stay paused
            // forever behind a banner pointing at a screen it cannot open.
            'their track record' => ['project_facts' => 'Northwind brand system.', 'proposal_signature' => 'Nora'],
            'proposal shape' => ['proposal_min_words' => 100, 'proposal_max_words' => 160],
            'workspace 2FA policy' => ['require_2fa' => true],
            'their AI spend cap' => ['ai_monthly_token_cap' => 500000, 'ai_hard_stop_on_cap' => true],
        ];

        foreach ($theirs as $label => $payload) {
            $this->asAdmin()->postJson('/api/settings', $payload)
                ->assertOk("an admin must be able to change {$label} in their own workspace");
        }

        // And the values actually landed on THEIR workspace.
        Tenancy::runAs($this->workspace, function () {
            $settings = app(SettingsService::class);

            $this->assertSame(300, (int) $settings->get('min_budget'));
            $this->assertSame(['Figma'], $settings->get('core_stacks'));
            $this->assertSame(9, (int) $settings->get('notify_score_min'));
            $this->assertSame('Nora', $settings->get('proposal_signature'));
        });
    }

    public function test_an_admin_reaches_every_screen_their_workspace_has(): void
    {
        foreach ([
            'settings' => '/api/settings',
            'workspace' => '/api/workspace',
            'diagnostics' => '/api/diagnostics',
            'members' => '/api/members',
            'roles matrix' => '/api/roles-matrix',
            'notifications' => '/api/notifications',
            'saved filters' => '/api/saved-filters',
            'AI usage' => '/api/ai-usage',
        ] as $label => $url) {
            $this->asAdmin()->getJson($url)->assertOk("an admin must be able to open {$label}");
        }
    }

    public function test_an_admin_can_rename_their_workspace_and_set_its_specialization(): void
    {
        $this->asAdmin()->putJson('/api/workspace', [
            'name' => 'Northwind Design',
            'slug' => 'northwind',
            'specialization' => 'Graphic design',
        ])->assertOk();

        $this->assertSame('Northwind Design', $this->workspace->fresh()->name);
        $this->assertSame('Graphic design', $this->workspace->fresh()->specialization);
    }

    public function test_an_admin_can_bring_in_their_own_bidders_and_viewers(): void
    {
        foreach (['bidder', 'viewer'] as $role) {
            $this->asAdmin()->postJson('/api/members/invite', [
                'email' => "{$role}@northwind.test", 'role' => $role,
            ])->assertOk();
        }
    }

    /**
     * The other half of the line, and the reason it is drawn where it is.
     *
     * These used to come back 200 while saving nothing — the keys had been
     * removed from the accepted vocabulary, so Laravel's validated() dropped
     * them silently. Safe, but it told the caller their change had been
     * saved when it had not.
     */
    public function test_the_product_itself_is_refused_out_loud_not_silently_ignored(): void
    {
        $platformProperty = [
            'scoring_system_prompt' => 'my own rubric',
            'proposal_skill' => 'my own drafting rules',
            'anthropic_api_key' => 'sk-ant-mine',
            'scoring_model' => 'claude-opus-4-8',
            'signup_mode' => 'open',
            'mail_host' => 'smtp.mine.test',
            // The lead source is the platform's single subscription, not a
            // per-workspace integration.
            'vollna_api_token' => 'vln-mine',
            'vollna_webhook_secret' => 'whsec-mine',
            'gmail_app_password' => 'mine mine mine mine',
        ];

        foreach ($platformProperty as $key => $value) {
            $response = $this->asAdmin()->postJson('/api/settings', [$key => $value]);

            $response->assertStatus(403);
            $this->assertStringContainsString(
                'platform',
                strtolower((string) $response->json('message')),
                "refusing [{$key}] must explain that it belongs to the platform",
            );
        }

        // Nothing was written for any of them, on any layer.
        $this->assertSame(0, DB::table('settings')
            ->whereIn('key', array_keys($platformProperty))
            ->where('tenant_id', $this->workspace->id)
            ->count());
    }

    /**
     * Filling in the track record must actually lift the pause — the banner
     * is only honest if the thing it asks for is the thing that clears it.
     */
    public function test_filling_in_the_track_record_lets_proposals_run(): void
    {
        $readiness = fn () => Tenancy::runAs(
            $this->workspace,
            fn () => app(WorkspaceReadiness::class)->banner(),
        );

        $this->asAdmin()->postJson('/api/settings', ['core_stacks' => ['Figma']])->assertOk();

        $banner = $readiness();
        $this->assertNotNull($banner);
        $this->assertTrue($banner['can_score'], 'scoring is ready; only proposals are waiting');

        // Every remaining gap points at a tab an admin can actually open.
        foreach ($banner['missing'] as $item) {
            $this->assertSame('track-record', $item['section']);
        }

        $this->asAdmin()->postJson('/api/settings', [
            'project_facts' => 'Northwind brand system. Figma, Illustrator.',
            'proposal_signature' => 'Nora',
        ])->assertOk();

        $this->assertNull($readiness(), 'the banner must clear once the workspace has filled it in');
    }

    public function test_an_admin_cannot_reach_the_platform_console_at_all(): void
    {
        $this->asAdmin()->getJson('/api/platform/tenants')->assertStatus(403);
        $this->asAdmin()->postJson('/api/platform/tenants', [
            'name' => 'Sneaky', 'slug' => 'sneaky', 'owner_email' => 'me@example.test',
        ])->assertStatus(403);
    }

    /**
     * The founding workspace is exempt, and must stay exempt — it is how the
     * platform owner edits the product's own defaults at all.
     */
    public function test_the_platform_owning_workspace_is_not_caught_by_the_refusal(): void
    {
        app(TenantContext::class)->set($this->tenant); // plan 'internal'
        config(['tenancy.default_tenant_slug' => $this->tenant->slug]);

        $founder = User::factory()->create();
        $this->tenant->users()->syncWithoutDetaching([$founder->id => ['joined_at' => now()]]);
        TenantTeamResolver::pinnedTo($this->tenant->id, fn () => $founder->syncRoles([TenantRole::Owner->value]));

        Auth::forgetGuards();
        $this->withToken(Tenancy::runAs($this->tenant, fn () => $founder->createToken('t')->plainTextToken))
            ->postJson('/api/settings', ['mail_host' => 'smtp.platform.test'])
            ->assertOk();
    }

    /**
     * An admin's Settings payload must not contain the platform's
     * credentials at all — not even masked.
     *
     * A workspace owner holds every permission in their own workspace, which
     * includes the setting.{key} permission for keys that are not theirs.
     * That was enough to put the platform's Vollna token and pooled AI keys
     * into their response as masked entries: the last four characters of a
     * credential funding every workspace, plus proof of which are set. A
     * masked secret is still a secret partly disclosed.
     */
    public function test_no_platform_credential_reaches_an_admins_settings_payload(): void
    {
        // Give the platform real values to leak.
        Tenancy::runAs($this->tenant, fn () => app(SettingsService::class)->setMany([
            'vollna_api_token' => 'vln-PLATFORM-SECRET',
            'vollna_webhook_secret' => 'whsec-PLATFORM',
            'gmail_app_password' => 'abcd efgh ijkl mnop',
            'anthropic_api_key' => 'sk-ant-PLATFORM',
            'mail_password' => 'smtp-PLATFORM',
        ]));

        $body = $this->asAdmin()->getJson('/api/settings')->assertOk()->getContent();
        $payload = json_decode($body, true)['data'];

        foreach ((array) config('tenancy.platform_only_keys') as $key) {
            foreach ($payload as $group => $entries) {
                $this->assertArrayNotHasKey(
                    $key,
                    $entries,
                    "[{$key}] belongs to the platform and must not appear in a workspace's settings payload",
                );
            }
        }

        // And no fragment of any of them is anywhere in the response.
        foreach (['vln-PLATFORM-SECRET', 'whsec-PLATFORM', 'ijkl mnop', 'sk-ant-PLATFORM', 'smtp-PLATFORM'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
            $this->assertStringNotContainsString(substr($secret, -4), $body);
        }
    }

    /**
     * The platform-owning workspace keeps them, and has to: writes from it
     * are redirected to the platform layer, so this is where they are edited.
     */
    public function test_the_platform_owner_still_sees_the_platform_settings(): void
    {
        app(TenantContext::class)->set($this->tenant);
        config(['tenancy.default_tenant_slug' => $this->tenant->slug]);

        $founder = User::factory()->create(['platform_role' => 'platform_owner']);
        $this->tenant->users()->syncWithoutDetaching([$founder->id => ['joined_at' => now()]]);
        TenantTeamResolver::pinnedTo($this->tenant->id, fn () => $founder->syncRoles([TenantRole::Owner->value]));

        Auth::forgetGuards();
        $payload = $this->withToken(Tenancy::runAs($this->tenant, fn () => $founder->createToken('t')->plainTextToken))
            ->getJson('/api/settings')->assertOk()->json('data');

        $this->assertArrayHasKey('vollna_api_token', $payload['vollna']);
        $this->assertArrayHasKey('anthropic_api_key', $payload['ai']);
        $this->assertArrayHasKey('mail_host', $payload['mail']);
    }
}
