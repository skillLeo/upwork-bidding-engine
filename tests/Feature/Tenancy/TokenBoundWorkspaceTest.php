<?php

namespace Tests\Feature\Tenancy;

use App\Authorization\RoleProvisioner;
use App\Authorization\TenantRole;
use App\Models\Lead;
use App\Models\SavedFilter;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Tenancy;
use App\Tenancy\TenantTeamResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A session lands in the workspace its token was issued for.
 *
 * THE BUG THIS CLOSES, and it made every workspace but the first useless.
 * Tenant resolution read the HOST only. The live host is upwork.skillleo.com,
 * whose first label is "upwork" — not a tenant slug — so host resolution
 * always missed and every request fell through to the configured fallback,
 * workspace 1. A brand-new workspace owner signing in was therefore bound to
 * the FOUNDER'S workspace, where they hold no role: zero permissions, no
 * navigation, no Settings page, and the founder's saved filters on screen.
 *
 * Subdomain-per-tenant would have fixed it, but it assumes wildcard DNS this
 * deployment does not have, so it was never going to arrive on its own.
 *
 * These tests deliberately do NOT set a tenant subdomain — they run against
 * the single shared host, which is the situation that was broken.
 */
class TokenBoundWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $other;

    private User $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();

        // The founding workspace ($this->tenant, slug 'skillleo') stays the
        // configured fallback — exactly as production has it.
        config(['tenancy.default_tenant_slug' => 'skillleo']);

        $this->other = Tenancy::asPlatform(fn () => Tenant::create([
            'name' => 'Northwind', 'slug' => 'northwind',
            'plan' => 'free', 'status' => Tenant::STATUS_ACTIVE,
        ]));

        app(RoleProvisioner::class)->provision($this->other);

        $this->otherOwner = User::factory()->create(['email' => 'nora@northwind.test']);
        $this->other->users()->syncWithoutDetaching([$this->otherOwner->id => ['joined_at' => now()]]);
        $this->other->update(['owner_user_id' => $this->otherOwner->id]);

        TenantTeamResolver::pinnedTo(
            $this->other->id,
            fn () => $this->otherOwner->syncRoles([TenantRole::Owner->value]),
        );

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    /**
     * Mints a token the way a real sign-in does.
     *
     * Sanctum's createToken() knows nothing about tenant_id — TokenIssuer
     * stamps it immediately afterwards, and so does the impersonation flow.
     * A test that skips that step is testing a token shape the app never
     * actually issues, which is how this whole class of bug hides.
     */
    private function tokenFor(User $user, Tenant $tenant): string
    {
        return Tenancy::runAs($tenant, function () use ($user, $tenant) {
            $token = $user->createToken('t');
            $token->accessToken->forceFill(['tenant_id' => $tenant->id])->save();

            return $token->plainTextToken;
        });
    }

    public function test_a_second_workspaces_owner_lands_in_their_own_workspace_not_the_fallback(): void
    {
        Auth::forgetGuards();

        $this->withToken($this->tokenFor($this->otherOwner, $this->other))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.tenant.slug', 'northwind')
            ->assertJsonPath('data.tenant_role', 'owner');
    }

    /**
     * The symptom exactly as it was reported: signed in, but the account menu
     * is the only thing on the page.
     */
    public function test_that_owner_actually_has_permissions_and_can_open_settings(): void
    {
        $token = $this->tokenFor($this->otherOwner, $this->other);

        Auth::forgetGuards();
        $permissions = $this->withToken($token)->getJson('/api/me')->json('data.permissions');
        $this->assertNotEmpty($permissions, 'an owner bound to the fallback workspace has no permissions at all');

        foreach (['/api/settings', '/api/workspace', '/api/members', '/api/diagnostics'] as $url) {
            Auth::forgetGuards();
            $this->withToken($token)->getJson($url)->assertOk("{$url} must be reachable");
        }
    }

    public function test_the_founders_data_is_not_visible_from_the_new_workspace(): void
    {
        // Give the founding workspace something to leak.
        Tenancy::runAs($this->tenant, function () {
            Lead::factory()->count(3)->create();
            SavedFilter::create(['name' => 'Web + Backend', 'criteria' => ['keywords' => ['laravel']]]);
        });

        Auth::forgetGuards();
        $token = $this->tokenFor($this->otherOwner, $this->other);

        $this->withToken($token)->getJson('/api/leads')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/saved-filters')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_founders_own_session_is_unaffected(): void
    {
        $founder = User::factory()->create();
        $this->tenant->users()->syncWithoutDetaching([$founder->id => ['joined_at' => now()]]);
        TenantTeamResolver::pinnedTo($this->tenant->id, fn () => $founder->syncRoles([TenantRole::Owner->value]));

        Auth::forgetGuards();
        $this->withToken($this->tokenFor($founder, $this->tenant))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.tenant.slug', 'skillleo');
    }

    /**
     * A token is not a way to reach a workspace you were never given. The
     * binding only decides WHICH workspace is in scope; the role check is
     * still what decides whether anything can be done there.
     */
    public function test_a_token_cannot_be_used_to_enter_a_workspace_the_user_left(): void
    {
        $token = $this->tokenFor($this->otherOwner, $this->other);

        // They are removed from the workspace after the token was issued.
        Tenancy::runAs($this->other, fn () => $this->otherOwner->syncRoles([]));
        $this->other->users()->detach($this->otherOwner->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/settings')->assertStatus(403);
    }

    /**
     * An unauthenticated request has no token to read, so it still falls back
     * — which is what keeps the public sign-in and branding routes working.
     */
    public function test_an_anonymous_request_still_falls_back_to_the_default_workspace(): void
    {
        $this->getJson('/api/branding')->assertOk();
    }

    /**
     * The root cause, tested at the source: signing in on the public host
     * must stamp the token with a workspace this person actually belongs to,
     * NOT the fallback that host resolves to.
     */
    public function test_signing_in_stamps_the_users_own_workspace_not_the_fallback(): void
    {
        $this->otherOwner->forceFill(['password' => Hash::make('password123')])->save();

        Auth::forgetGuards();

        $token = $this->postJson('/api/auth/login', [
            'email' => 'nora@northwind.test',
            'password' => 'password123',
        ])->assertOk()->json('data.token');

        $id = explode('|', $token)[0];

        $this->assertSame(
            $this->other->id,
            (int) DB::table('personal_access_tokens')->where('id', $id)->value('tenant_id'),
            'the token was stamped with the fallback workspace instead of their own',
        );

        // And the very next request lands in the right place.
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.tenant.slug', 'northwind')
            ->assertJsonPath('data.tenant_role', 'owner');
    }
}
