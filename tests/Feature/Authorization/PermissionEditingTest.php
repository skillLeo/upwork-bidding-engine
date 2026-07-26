<?php

namespace Tests\Feature\Authorization;

use App\Authorization\Permissions;
use App\Authorization\RoleProvisioner;
use App\Models\Lead;
use App\Models\PermissionDeny;
use App\Models\User;
use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The editable-permissions decision (2026-07-24), proven: roles are
 * editable, users get personal grant/deny overrides, and the Owner is
 * locked no matter what.
 */
class PermissionEditingTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->admin()->create();
        Tenancy::runAs($this->tenant, fn () => $owner->syncRoles(['owner']));
        $this->tenant->update(['owner_user_id' => $owner->id]);

        return $owner;
    }

    // ----------------------------------------------------- editable matrix

    public function test_an_admin_with_permissions_edit_can_change_what_a_role_may_do(): void
    {
        $admin = User::factory()->admin()->create();

        // Viewer starts read-only; grant it AI rewrite through the API.
        $viewerGrants = Role::where('name', 'viewer')->first()->permissions->pluck('name')->all();
        $this->assertNotContains(Permissions::PROPOSALS_AI_REWRITE, $viewerGrants);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/roles/viewer/permissions', [
                'granted' => [...$viewerGrants, Permissions::PROPOSALS_AI_REWRITE],
            ])
            ->assertOk();

        // A viewer NOW holds the rewrite permission — fully dynamic.
        $viewer = User::factory()->bidder()->create();
        Tenancy::runAs($this->tenant, fn () => $viewer->syncRoles(['viewer']));

        $this->assertTrue($viewer->can(Permissions::PROPOSALS_AI_REWRITE));
    }

    public function test_the_owner_role_cannot_be_edited_by_anyone(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/roles/owner/permissions', ['granted' => [Permissions::LEADS_VIEW]])
            ->assertStatus(422);

        // Still holds everything.
        $this->assertTrue($owner->can(Permissions::BILLING_MANAGE));
        $this->assertTrue($owner->can(Permissions::PERMISSIONS_EDIT));
    }

    public function test_permission_editing_is_itself_gated_by_permissions_edit(): void
    {
        $bidder = User::factory()->bidder()->create(); // bidder default lacks permissions.edit

        $this->actingAs($bidder, 'sanctum')
            ->putJson('/api/roles/viewer/permissions', ['granted' => []])
            ->assertStatus(403);
    }

    public function test_a_workspaces_role_edits_survive_reprovisioning(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/roles/viewer/permissions', ['granted' => [Permissions::LEADS_VIEW]])
            ->assertOk();

        // A deploy re-runs the provisioner. The custom viewer config must
        // survive; only owner is re-synced.
        app(RoleProvisioner::class)->provision($this->tenant);

        $granted = Role::where('name', 'viewer')->first()->permissions->pluck('name')->all();
        $this->assertSame([Permissions::LEADS_VIEW], $granted);
    }

    // ------------------------------------------------- per-user overrides

    public function test_a_personal_grant_adds_on_top_of_the_role(): void
    {
        $admin = User::factory()->admin()->create();
        $bidder = User::factory()->bidder()->create();

        // Bidder's role does not include the Anthropic key.
        $this->assertFalse($bidder->can('setting.vollna_api_token'));

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/members/{$bidder->id}/overrides", [
                'grants' => ['setting.vollna_api_token'],
                'denies' => [],
            ])
            ->assertOk();

        $this->assertTrue($bidder->fresh()->can('setting.vollna_api_token'));
    }

    public function test_a_personal_deny_beats_the_role_grant(): void
    {
        $admin = User::factory()->admin()->create();
        $bidder = User::factory()->bidder()->create();

        // Role grants AI rewrite; deny it for THIS person only.
        $this->assertTrue($bidder->can(Permissions::PROPOSALS_AI_REWRITE));

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/members/{$bidder->id}/overrides", [
                'grants' => [],
                'denies' => [Permissions::PROPOSALS_AI_REWRITE],
            ])
            ->assertOk();

        PermissionDeny::flushRequestCache();

        $this->assertFalse($bidder->fresh()->can(Permissions::PROPOSALS_AI_REWRITE));

        // And another bidder with the same role is untouched.
        $other = User::factory()->bidder()->create();
        $this->assertTrue($other->can(Permissions::PROPOSALS_AI_REWRITE));
    }

    public function test_a_denied_user_is_refused_over_http_not_just_in_memory(): void
    {
        $admin = User::factory()->admin()->create();
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->ready()->create();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/members/{$bidder->id}/overrides", [
                'grants' => [],
                'denies' => [Permissions::LEADS_UPDATE_STATUS],
            ])->assertOk();

        PermissionDeny::flushRequestCache();

        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/status", ['status' => 'sent'])
            ->assertStatus(403);
    }

    public function test_the_owner_cannot_be_denied_anything(): void
    {
        $owner = $this->owner();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/members/{$owner->id}/overrides", [
                'grants' => [],
                'denies' => [Permissions::PERMISSIONS_EDIT],
            ])
            ->assertStatus(422);
    }

    // ------------------------------------------------ per-key settings

    public function test_every_settings_key_is_its_own_permission(): void
    {
        $bidder = User::factory()->bidder()->create();

        // A non-secret rules key: allowed by the bidder default.
        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/settings', ['score_cutoff' => 6])
            ->assertOk();

        // A secret key: refused, naming the key. (A workspace secret — the
        // Anthropic key is platform-owned since P8 and never reaches this
        // endpoint at all.)
        $res = $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/settings', ['vollna_api_token' => 'vln-x'])
            ->assertStatus(403);
        $this->assertStringContainsString('vollna_api_token', $res->json('message'));

        // Deny ONE specific rules key for this person; the rest still work.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/members/{$bidder->id}/overrides", [
                'grants' => [], 'denies' => ['setting.score_cutoff'],
            ])->assertOk();

        PermissionDeny::flushRequestCache();

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/settings', ['score_cutoff' => 8])
            ->assertStatus(403);
        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/settings', ['min_budget' => 200])
            ->assertOk();
    }

    public function test_secret_visibility_follows_the_per_key_permission(): void
    {
        app(SettingsService::class)->set('vollna_api_token', 'vln-real');
        $admin = User::factory()->admin()->create();
        $bidder = User::factory()->bidder()->create();

        // Absent for the bidder…
        $body = $this->actingAs($bidder, 'sanctum')->getJson('/api/settings')->json('data');
        $this->assertArrayNotHasKey('vollna_api_token', $body['vollna'] ?? []);

        // …until someone grants exactly that key.
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/members/{$bidder->id}/overrides", [
                'grants' => ['setting.vollna_api_token'], 'denies' => [],
            ])->assertOk();

        // fresh(): actingAs reuses the same model instance, whose permissions
        // relation was cached (empty) before the grant. A real HTTP request
        // re-resolves the user from the database, which fresh() mirrors.
        $body = $this->actingAs($bidder->fresh(), 'sanctum')->getJson('/api/settings')->json('data');
        $this->assertArrayHasKey('vollna_api_token', $body['vollna']);
        $this->assertTrue($body['vollna']['vollna_api_token']['is_set']);
    }

    public function test_the_matrix_endpoint_reports_live_grants_and_the_owner_lock(): void
    {
        $admin = User::factory()->admin()->create();

        $data = $this->actingAs($admin, 'sanctum')->getJson('/api/roles-matrix')->assertOk()->json('data');

        $roles = collect($data['roles']);
        $this->assertTrue($roles->firstWhere('value', 'owner')['locked']);
        $this->assertFalse($roles->firstWhere('value', 'bidder')['locked']);
        $this->assertTrue($data['can_edit']);

        // The grouped catalog covers every permission exactly once.
        $flat = collect($data['groups'])->flatten()->all();
        $this->assertEqualsCanonicalizing(Permissions::all(), $flat);
    }
}
