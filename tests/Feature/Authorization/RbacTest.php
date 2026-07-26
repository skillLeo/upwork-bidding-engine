<?php

namespace Tests\Feature\Authorization;

use App\Authorization\TenantRole;
use App\Models\Lead;
use App\Models\User;
use App\Services\Auth\TokenIssuer;
use App\Services\Members\InvitationService;
use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * P4 verify block, as executable tests.
 */
class RbacTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->admin()->create();
        Tenancy::runAs($this->tenant, fn () => $owner->syncRoles(['owner']));
        $this->tenant->update(['owner_user_id' => $owner->id]);

        return $owner;
    }

    // ---------------------------------------------------------------- verify (a)

    public function test_a_bidder_gets_403_on_edit_secrets_and_the_secret_is_absent_not_masked(): void
    {
        // The Vollna token is the workspace's own secret. (The Anthropic key
        // used to stand here; since P8 it is platform-owned and never reaches
        // this endpoint at all, which is proven separately in
        // PlatformAiCustodyTest.)
        app(SettingsService::class)->set('vollna_api_token', 'vln-real-secret');

        $bidder = User::factory()->bidder()->create();

        // GET: the key is ABSENT from the body entirely — not present with a
        // masked value.
        $body = $this->actingAs($bidder, 'sanctum')->getJson('/api/settings')->assertOk()->json('data');
        $this->assertArrayNotHasKey('vollna_api_token', $body['vollna'] ?? []);

        // WRITE: a secret key is a hard 403.
        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/settings', ['vollna_api_token' => 'vln-hijack'])
            ->assertStatus(403);

        // The stored value is untouched.
        $this->assertSame('vln-real-secret', app(SettingsService::class)->get('vollna_api_token'));

        // A user WITH edit_secrets does see it (masked shape), proving the
        // difference is permission, not a blanket hide.
        $owner = $this->owner();
        $ownerBody = $this->actingAs($owner, 'sanctum')->getJson('/api/settings')->assertOk()->json('data');
        $this->assertArrayHasKey('vollna_api_token', $ownerBody['vollna']);
        $this->assertTrue($ownerBody['vollna']['vollna_api_token']['is_set']);
    }

    // ---------------------------------------------------------------- verify (b)

    public function test_an_invite_token_cannot_be_reused(): void
    {
        Mail::fake();
        $owner = $this->owner();

        $invitation = app(InvitationService::class)->invite(
            $this->tenant, 'newbie@example.com', TenantRole::Bidder, $owner,
        );
        $raw = $invitation->rawToken;

        // First accept succeeds.
        $this->postJson('/api/invitations/accept', [
            'token' => $raw,
            'name' => 'Newbie',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertOk();

        // Second use of the same token is refused — single use.
        $this->postJson('/api/invitations/accept', ['token' => $raw])->assertStatus(404);
    }

    public function test_resending_invalidates_the_previous_token(): void
    {
        Mail::fake();
        $owner = $this->owner();
        $service = app(InvitationService::class);

        $invitation = $service->invite($this->tenant, 'x@example.com', TenantRole::Bidder, $owner);
        $oldRaw = $invitation->rawToken;

        $service->resend($this->tenant, $invitation, $owner);

        // The old link is dead.
        $this->postJson('/api/invitations/accept', ['token' => $oldRaw])->assertStatus(404);
    }

    // ---------------------------------------------------------------- verify (c)

    public function test_removing_a_member_immediately_invalidates_their_tokens(): void
    {
        $owner = $this->owner();
        $member = User::factory()->bidder()->create();

        Tenancy::runAs($this->tenant, fn () => app(TokenIssuer::class)->issue($member));
        $this->assertSame(1, $member->tokens()->count());

        // Owner removes them.
        $this->actingAs($owner, 'sanctum')->deleteJson("/api/members/{$member->id}")->assertOk();

        // Every token the member held for this tenant is deleted immediately —
        // the row is gone, so any future request 401s. (Asserted at the DB
        // rather than via a second in-process request, which Sanctum's
        // per-process user cache would otherwise mask.)
        $this->assertSame(0, $member->fresh()->tokens()->count());
        $this->assertFalse($this->tenant->users()->whereKey($member->id)->exists());
    }

    public function test_the_sole_owner_cannot_be_removed_or_demoted(): void
    {
        $owner = $this->owner();

        // Cannot remove.
        $this->actingAs($owner, 'sanctum')->deleteJson("/api/members/{$owner->id}")->assertStatus(422);

        // Cannot demote the last owner.
        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/members/{$owner->id}/role", ['role' => 'admin'])
            ->assertStatus(422);
    }

    // --------------------------------------------------------------- enforcement

    public function test_a_viewer_can_read_leads_but_cannot_change_status(): void
    {
        $viewer = User::factory()->bidder()->create();
        Tenancy::runAs($this->tenant, fn () => $viewer->syncRoles(['viewer']));
        $lead = Lead::factory()->ready()->create();

        $this->actingAs($viewer, 'sanctum')->getJson("/api/leads/{$lead->id}")->assertOk();
        $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/status", ['status' => 'sent'])
            ->assertStatus(403);
    }

    /**
     * P8 narrowed this: it used to read "only an owner can invite another
     * owner". Nobody can now. A workspace has exactly one owner, reached by
     * creation or transfer, and the only roles anyone hands out are bidder
     * and viewer. The full matrix is proven in InvitationMatrixTest.
     */
    public function test_nobody_can_invite_another_owner(): void
    {
        Mail::fake();
        $owner = $this->owner();

        // The owner inviting a plain bidder is fine.
        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/members/invite', ['email' => 'b@example.com', 'role' => 'bidder'])
            ->assertOk();

        // Even the owner cannot mint a second one.
        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/members/invite', ['email' => 'o@example.com', 'role' => 'owner'])
            ->assertStatus(403);
    }

    // --------------------------------------------------------- roles matrix + attribution

    public function test_the_roles_matrix_is_read_only_data(): void
    {
        $owner = $this->owner();

        $res = $this->actingAs($owner, 'sanctum')->getJson('/api/roles-matrix')->assertOk();

        $roles = collect($res->json('data.roles'));
        // Three since P8: owner, bidder, viewer. The tenant 'admin' role was
        // removed with the final hierarchy.
        $this->assertCount(3, $roles);
        $this->assertSame(['owner', 'bidder', 'viewer'], $roles->pluck('value')->all());

        $bidder = $roles->firstWhere('value', 'bidder');
        $this->assertContains('leads.view', $bidder['granted']);
        $this->assertNotContains('settings.edit_secrets', $bidder['granted']);

        $ownerRole = $roles->firstWhere('value', 'owner');
        $this->assertContains('billing.manage', $ownerRole['granted']);
    }

    public function test_status_to_sent_stamps_the_submitting_user(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->ready()->create();

        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/status", ['status' => 'sent'])
            ->assertOk();

        $this->assertSame($bidder->id, $lead->fresh()->submitted_by_user_id);

        // A re-send by someone else never overwrites the original submitter.
        $other = User::factory()->bidder()->create();
        $this->actingAs($other, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/status", ['status' => 'sent'])
            ->assertOk();

        $this->assertSame($bidder->id, $lead->fresh()->submitted_by_user_id);
    }

    public function test_analytics_segments_by_bidder_with_a_min_sample_floor(): void
    {
        $owner = $this->owner();
        $prolific = User::factory()->bidder()->create(['name' => 'Prolific']);
        $quiet = User::factory()->bidder()->create(['name' => 'Quiet']);

        Lead::factory()->count(6)->sent()->create(['submitted_by_user_id' => $prolific->id]);
        Lead::factory()->count(2)->sent()->create(['submitted_by_user_id' => $quiet->id]);

        $byBidder = collect($this->actingAs($owner, 'sanctum')->getJson('/api/analytics')->json('data.by_bidder'));

        $p = $byBidder->firstWhere('user_id', $prolific->id);
        $q = $byBidder->firstWhere('user_id', $quiet->id);

        $this->assertSame(6, $p['sent']);
        $this->assertNotNull($p['reply_rate'], 'n>=5 shows a rate');

        $this->assertSame(2, $q['sent']);
        $this->assertNull($q['reply_rate'], 'n<5 renders "not enough data"');
    }
}
