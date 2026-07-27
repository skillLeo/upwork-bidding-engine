<?php

namespace Tests\Feature\Platform;

use App\Authorization\RoleProvisioner;
use App\Exceptions\AiQuotaExceededException;
use App\Models\AiCall;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\AiManager;
use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use App\Tenancy\TenantTeamResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * P5 platform console + AI quotas, proven end to end (2026-07-24 phase pack).
 *
 * Every request here authenticates via a REAL minted Sanctum token through
 * asToken(), never actingAs($user, 'sanctum') or a bare withToken(). Two
 * layers of Laravel/Sanctum test state persist across simulated requests
 * WITHIN one test method (the app container isn't rebuilt between calls):
 *   1. actingAs($user, 'sanctum') pins that user directly onto the guard,
 *      bypassing bearer-token resolution entirely for the rest of the test.
 *   2. Sanctum's RequestGuard (a Laravel RequestGuard under the hood) also
 *      CACHES the first resolved user on the guard OBJECT itself
 *      (`if (!is_null($this->user)) return $this->user;`) — so even a
 *      later withToken() call with a genuinely different token's header
 *      silently resolves back to whichever identity authenticated FIRST.
 * asToken() calls Auth::forgetGuards() before every withToken() specifically
 * to defeat #2 — required any time one test method impersonates, or
 * otherwise authenticates as, more than one identity in sequence (which is
 * this whole file's point).
 */
class PlatformConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function platformUser(string $role = 'platform_owner'): User
    {
        return User::factory()->admin()->create(['platform_role' => $role]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function asToken(string $token): TestResponse|static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    // ------------------------------------------------------------- P5 VERIFY a

    public function test_a_bidders_settings_response_contains_no_secret_keys_at_all(): void
    {
        foreach (SettingsService::SCHEMA as $key => $meta) {
            if ($meta['secret']) {
                app(SettingsService::class)->set($key, 'super-secret-value-'.$key);
            }
        }

        $bidder = User::factory()->bidder()->create();

        $body = $this->asToken($this->tokenFor($bidder))->getJson('/api/settings')->assertOk()->json('data');

        foreach (SettingsService::SCHEMA as $key => $meta) {
            if (! $meta['secret']) {
                continue;
            }

            $this->assertArrayNotHasKey(
                $key,
                $body[$meta['group']] ?? [],
                "bidder's /settings response leaked secret key [{$key}]"
            );
        }

        $this->assertStringNotContainsString('super-secret-value', json_encode($body));
    }

    // ------------------------------------------------------------- P5 VERIFY b

    public function test_b_platform_support_cannot_read_proposal_text_through_any_platform_route(): void
    {
        $support = $this->platformUser('platform_support');
        $supportToken = $this->tokenFor($support);
        Lead::factory()->create(['proposal_text' => 'THE SECRET PROPOSAL BODY THAT MUST NEVER LEAK']);

        $listBody = $this->asToken($supportToken)->getJson('/api/platform/tenants')->assertOk()->getContent();
        $this->assertStringNotContainsString('THE SECRET PROPOSAL BODY', $listBody);

        $detailBody = $this->asToken($supportToken)
            ->getJson("/api/platform/tenants/{$this->tenant->id}")
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('THE SECRET PROPOSAL BODY', $detailBody);

        // The stronger door — literally becoming a tenant user — is refused
        // outright for support, not merely content-filtered.
        $bidder = User::factory()->bidder()->create();
        $this->asToken($supportToken)
            ->postJson("/api/platform/tenants/{$this->tenant->id}/users/{$bidder->id}/impersonate", ['reason' => 'poking around'])
            ->assertStatus(403);
    }

    public function test_platform_billing_role_also_cannot_impersonate(): void
    {
        $billing = $this->platformUser('platform_billing');
        $bidder = User::factory()->bidder()->create();

        $this->asToken($this->tokenFor($billing))
            ->postJson("/api/platform/tenants/{$this->tenant->id}/users/{$bidder->id}/impersonate", ['reason' => 'billing check'])
            ->assertStatus(403);
    }

    // ------------------------------------------------------------- P5 VERIFY c

    public function test_c_an_impersonating_session_is_refused_on_every_write_endpoint(): void
    {
        $platformOwnerToken = $this->tokenFor($this->platformUser('platform_owner'));
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->ready()->create();

        $start = $this->asToken($platformOwnerToken)
            ->postJson("/api/platform/tenants/{$this->tenant->id}/users/{$bidder->id}/impersonate", [
                'reason' => 'investigating support ticket #482',
            ])
            ->assertOk();

        $token = $start->json('data.token');

        // Reads still work — this is read-only, not locked out.
        $this->asToken($token)->getJson('/api/leads')->assertOk();
        $this->asToken($token)->getJson('/api/me')->assertOk();

        // Every write is refused, across unrelated endpoints.
        $this->asToken($token)
            ->postJson("/api/leads/{$lead->id}/favorite")
            ->assertStatus(403)
            ->assertJsonPath('message', 'This session is impersonating a user and is read-only.');

        $this->asToken($token)
            ->putJson('/api/profile', ['name' => 'Changed While Impersonating'])
            ->assertStatus(403);

        $this->asToken($token)
            ->deleteJson('/api/profile/sessions/others')
            ->assertStatus(403);

        // Ending impersonation is the one write this session CAN make.
        $this->asToken($token)->postJson('/api/platform/impersonate/end')->assertOk();

        // And the auto-expiry: an impersonation token past its expiry is
        // refused entirely, not just for writes.
        $start2 = $this->asToken($platformOwnerToken)
            ->postJson("/api/platform/tenants/{$this->tenant->id}/users/{$bidder->id}/impersonate", ['reason' => 'expiry check'])
            ->assertOk();
        $expiredToken = $start2->json('data.token');

        PersonalAccessToken::findToken($expiredToken)
            ->forceFill(['impersonation_expires_at' => now()->subMinute()])->save();

        $this->asToken($expiredToken)->getJson('/api/leads')->assertStatus(401);
    }

    // ------------------------------------------------------------- P5 VERIFY d

    public function test_d_ai_manager_refuses_a_call_over_cap_with_hard_stop_on(): void
    {
        app(SettingsService::class)->setMany([
            'ai_monthly_token_cap' => 100,
            'ai_hard_stop_on_cap' => true,
            'anthropic_api_key' => 'sk-test-key',
        ]);

        AiCall::create([
            'purpose' => 'scoring', 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5',
            'input_tokens' => 80, 'output_tokens' => 30, 'success' => true,
        ]);

        Http::fake(); // must never even be reached

        $this->expectException(AiQuotaExceededException::class);

        app(AiManager::class)->complete('scoring', 'system prompt', 'user content', 'claude-haiku-4-5', 100);
    }

    public function test_ai_manager_does_not_refuse_over_cap_when_hard_stop_is_off(): void
    {
        app(SettingsService::class)->setMany([
            'ai_monthly_token_cap' => 100,
            'ai_hard_stop_on_cap' => false,
            'anthropic_api_key' => 'sk-test-key',
        ]);

        AiCall::create([
            'purpose' => 'scoring', 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5',
            'input_tokens' => 80, 'output_tokens' => 30, 'success' => true,
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => '{}']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
            ]),
        ]);

        $response = app(AiManager::class)->complete('scoring', 'system prompt', 'user content', 'claude-haiku-4-5', 100);

        $this->assertNotNull($response);
    }

    public function test_ai_manager_refuses_every_call_for_a_past_due_tenant_regardless_of_cap(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_PAST_DUE]);
        app(SettingsService::class)->set('anthropic_api_key', 'sk-test-key');
        Http::fake();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('suspended or past due');

        app(AiManager::class)->complete('scoring', 'system prompt', 'user content', 'claude-haiku-4-5', 100);
    }

    public function test_vollna_poller_skips_a_past_due_tenant_entirely(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_PAST_DUE]);
        app(SettingsService::class)->setMany(['vollna_api_token' => 'tok', 'vollna_filter_id' => '123']);
        Http::fake();

        $this->artisan('vollna:poll-api', ['--tenant' => $this->tenant->slug])->assertExitCode(0);

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------- extras

    public function test_the_tenants_list_reports_metadata_and_health(): void
    {
        $owner = $this->platformUser('platform_owner');

        $body = $this->asToken($this->tokenFor($owner))->getJson('/api/platform/tenants')->assertOk()->json('data.tenants');

        $mine = collect($body)->firstWhere('id', $this->tenant->id);
        $this->assertNotNull($mine);
        $this->assertArrayHasKey('plan', $mine);
        $this->assertArrayHasKey('status', $mine);
        $this->assertArrayHasKey('members', $mine);
        $this->assertArrayHasKey('health', $mine);
    }

    public function test_a_non_platform_user_is_refused_every_platform_route(): void
    {
        $bidder = User::factory()->bidder()->create();
        $token = $this->tokenFor($bidder);

        $this->asToken($token)->getJson('/api/platform/tenants')->assertStatus(403);
        $this->asToken($token)->getJson('/api/platform/health')->assertStatus(403);
        $this->asToken($token)->getJson('/api/platform/settings')->assertStatus(403);
    }

    /**
     * The platform owner can see every workspace and who is in it.
     *
     * The console answered "how many members" with a single total, which is
     * not the question that gets asked — how many bidders has this admin
     * taken on, is anyone sitting read-only, and who owns the place. And it
     * had no entry point in the app at all: reachable only by typing
     * /platform, so the one person who owns every workspace had no way in.
     */
    public function test_the_tenants_list_shows_each_workspaces_owner_and_role_breakdown(): void
    {
        $platformOwner = $this->platformUser();

        $workspace = Tenancy::asPlatform(fn () => Tenant::create([
            'name' => 'Northwind', 'slug' => 'northwind',
            'plan' => 'free', 'status' => Tenant::STATUS_ACTIVE,
        ]));

        app(RoleProvisioner::class)->provision($workspace);

        $owner = User::factory()->create(['email' => 'nora@northwind.test']);
        $workspace->update(['owner_user_id' => $owner->id]);

        $members = [$owner->id => 'owner'];
        foreach (range(1, 3) as $i) {
            $members[User::factory()->create(['email' => "bidder{$i}@northwind.test"])->id] = 'bidder';
        }
        $members[User::factory()->create(['email' => 'viewer@northwind.test'])->id] = 'viewer';

        foreach ($members as $userId => $role) {
            $workspace->users()->syncWithoutDetaching([$userId => ['joined_at' => now()]]);
            TenantTeamResolver::pinnedTo(
                $workspace->id,
                fn () => User::find($userId)->syncRoles([$role]),
            );
        }

        $row = collect($this->asToken($this->tokenFor($platformOwner))
            ->getJson('/api/platform/tenants')
            ->assertOk()
            ->json('data.tenants'))
            ->firstWhere('slug', 'northwind');

        $this->assertNotNull($row);
        $this->assertSame('nora@northwind.test', $row['owner_email']);
        $this->assertSame(5, $row['members']);
        $this->assertSame(1, $row['roles']['owner']);
        $this->assertSame(3, $row['roles']['bidder']);
        $this->assertSame(1, $row['roles']['viewer']);
    }
}
