<?php

namespace Tests\Feature\Authorization;

use App\Authorization\Permissions;
use App\Authorization\TenantRole;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Members\InvitationService;
use App\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * P8 VERIFY (b) and (c): who may invite whom, enforced on the SERVER.
 *
 * Every request here is crafted directly against the API with a role the UI
 * never offers, because that is the only interesting case. A hidden <option>
 * stops nobody; these tests are what actually stop them.
 */
class InvitationMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function asToken(User $user): TestResponse|static
    {
        Auth::forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    private function member(TenantRole $role): User
    {
        $user = User::factory()->create();
        $this->tenant->users()->syncWithoutDetaching([$user->id => ['joined_at' => now()]]);
        Tenancy::runAs($this->tenant, fn () => $user->syncRoles([$role->value]));

        return $user;
    }

    private function owner(): User
    {
        $owner = $this->member(TenantRole::Owner);
        $this->tenant->update(['owner_user_id' => $owner->id]);

        return $owner;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    // ------------------------------------------------- the three roles only

    public function test_there_are_exactly_three_tenant_roles(): void
    {
        $this->assertSame(['owner', 'bidder', 'viewer'], TenantRole::values());
        $this->assertNull(TenantRole::tryFrom('admin'), "'admin' must no longer be a tenant role");

        $roles = Tenancy::runAs($this->tenant, fn () => Role::pluck('name')->sort()->values()->all());
        $this->assertSame(['bidder', 'owner', 'viewer'], $roles);
    }

    // ------------------------------------------------------ VERIFY (b)

    public function test_a_workspace_owner_cannot_invite_another_owner(): void
    {
        $owner = $this->owner();

        $this->asToken($owner)
            ->postJson('/api/members/invite', ['email' => 'someone@example.test', 'role' => 'owner'])
            ->assertStatus(403);

        $this->assertDatabaseCount('invitations', 0);
        $this->assertDatabaseHas('activity_logs', ['type' => 'invitation_refused']);
        Mail::assertNothingSent();
    }

    public function test_a_workspace_owner_cannot_invite_an_admin_because_the_role_no_longer_exists(): void
    {
        $owner = $this->owner();

        // 'admin' is not in the enum any more, so this is refused at
        // validation. Either way it must never create an invitation — the
        // point of the assertion is the OUTCOME, not which layer caught it.
        $this->asToken($owner)
            ->postJson('/api/members/invite', ['email' => 'someone@example.test', 'role' => 'admin'])
            ->assertStatus(422);

        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_a_workspace_owner_can_invite_a_bidder_and_a_viewer(): void
    {
        $owner = $this->owner();

        foreach (['bidder' => 'b@example.test', 'viewer' => 'v@example.test'] as $role => $email) {
            $this->asToken($owner)
                ->postJson('/api/members/invite', ['email' => $email, 'role' => $role])
                ->assertOk();
        }

        $this->assertSame(
            ['bidder', 'viewer'],
            Tenancy::runAs($this->tenant, fn () => Invitation::orderBy('role')->pluck('role')->all()),
        );
    }

    // ------------------------------------------------------ VERIFY (c)

    public function test_a_bidder_cannot_invite_anyone_whatever_the_payload(): void
    {
        $this->owner();
        $bidder = $this->member(TenantRole::Bidder);

        // Grant the bidder members.invite explicitly — the Roles matrix is
        // editable, so an owner really can tick this box. The invitation
        // matrix is NOT a permission and must hold anyway.
        Tenancy::runAs($this->tenant, fn () => $bidder->givePermissionTo(Permissions::MEMBERS_INVITE));

        foreach (['owner', 'bidder', 'viewer'] as $role) {
            $this->asToken($bidder)
                ->postJson('/api/members/invite', ['email' => "x-{$role}@example.test", 'role' => $role])
                ->assertStatus(403);
        }

        $this->assertDatabaseCount('invitations', 0);
        Mail::assertNothingSent();
    }

    public function test_a_viewer_cannot_invite_anyone(): void
    {
        $this->owner();
        $viewer = $this->member(TenantRole::Viewer);

        Tenancy::runAs($this->tenant, fn () => $viewer->givePermissionTo(Permissions::MEMBERS_INVITE));

        $this->asToken($viewer)
            ->postJson('/api/members/invite', ['email' => 'x@example.test', 'role' => 'bidder'])
            ->assertStatus(403);

        $this->assertDatabaseCount('invitations', 0);
    }

    // ----------------------------------------------------------- re-roling

    public function test_a_member_cannot_be_promoted_to_owner_by_re_roling(): void
    {
        $owner = $this->owner();
        $bidder = $this->member(TenantRole::Bidder);

        $this->asToken($owner)
            ->putJson("/api/members/{$bidder->id}/role", ['role' => 'owner'])
            ->assertStatus(403);

        $this->assertSame(
            'bidder',
            Tenancy::runAs($this->tenant, fn () => $bidder->fresh()->getRoleNames()->first()),
        );
        $this->assertSame($owner->id, $this->tenant->fresh()->owner_user_id);
    }

    public function test_an_owner_may_move_a_member_between_bidder_and_viewer(): void
    {
        $owner = $this->owner();
        $member = $this->member(TenantRole::Bidder);

        $this->asToken($owner)
            ->putJson("/api/members/{$member->id}/role", ['role' => 'viewer'])
            ->assertOk();

        $this->assertSame(
            'viewer',
            Tenancy::runAs($this->tenant, fn () => $member->fresh()->getRoleNames()->first()),
        );
    }

    // ------------------------------------------- platform workspace creation

    public function test_the_platform_owner_creates_a_workspace_and_invites_its_owner(): void
    {
        $platformOwner = User::factory()->create(['platform_role' => 'platform_owner']);

        $this->asToken($platformOwner)
            ->postJson('/api/platform/tenants', [
                'name' => 'Northwind Design',
                'slug' => 'northwind-design',
                'specialization' => 'Graphic design',
                'owner_email' => 'owner@northwind.test',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.tenant.specialization', 'Graphic design')
            ->assertJsonPath('data.tenant.status', Tenant::STATUS_TRIALING);

        $created = Tenancy::asPlatform(fn () => Tenant::where('slug', 'northwind-design')->firstOrFail());

        // Ownerless until the invitation is accepted — that acceptance is
        // what stamps owner_user_id.
        $this->assertNull($created->owner_user_id);

        $invitation = Tenancy::runAs($created, fn () => Invitation::firstOrFail());
        $this->assertSame('owner', $invitation->role);
        $this->assertSame('owner@northwind.test', $invitation->email);

        // The three roles exist for the brand-new workspace, not the founding
        // one's — this is the second-tenant provisioning case that used to
        // silently no-op.
        $roles = Tenancy::runAs($created, fn () => Role::where('tenant_id', $created->id)->pluck('name')->sort()->values()->all());
        $this->assertSame(['bidder', 'owner', 'viewer'], $roles);
    }

    public function test_a_workspace_owner_cannot_create_a_workspace(): void
    {
        $owner = $this->owner();

        $this->asToken($owner)
            ->postJson('/api/platform/tenants', [
                'name' => 'Sneaky', 'slug' => 'sneaky', 'owner_email' => 'me@example.test',
            ])
            ->assertStatus(403);

        $this->assertSame(1, Tenancy::asPlatform(fn () => Tenant::count()));
    }

    public function test_accepting_an_owner_invitation_makes_that_person_the_workspace_owner(): void
    {
        $platformOwner = User::factory()->create(['platform_role' => 'platform_owner']);

        $this->asToken($platformOwner)->postJson('/api/platform/tenants', [
            'name' => 'Northwind', 'slug' => 'northwind', 'owner_email' => 'owner@northwind.test',
        ])->assertStatus(201);

        $created = Tenancy::asPlatform(fn () => Tenant::where('slug', 'northwind')->firstOrFail());
        $invitation = Tenancy::runAs($created, fn () => Invitation::firstOrFail());

        app(InvitationService::class)->accept($invitation, [
            'name' => 'New Owner',
            'password' => 'password123',
        ]);

        $newOwner = User::where('email', 'owner@northwind.test')->firstOrFail();

        $this->assertSame($newOwner->id, $created->fresh()->owner_user_id);

        // Pinned for the READ too. Binding the tenant is not enough: Spatie
        // answers through the team resolver, which prefers a leftover
        // override — so an unpinned read can look in a different workspace
        // than the write did and report a confident, wrong answer.
        $this->assertSame(
            'owner',
            \App\Tenancy\TenantTeamResolver::pinnedTo(
                $created->id,
                fn () => Tenancy::runAs($created->fresh(), fn () => $newOwner->fresh()->getRoleNames()->first()),
            ),
        );
    }

    /**
     * The role assignment lands in the RIGHT TEAM — asserted against the raw
     * pivot row, not through Spatie.
     *
     * This is not pedantry. The previous version of this file asked
     * getRoleNames() for the answer, which resolves through the same
     * TenantTeamResolver the write used. When an override was left pinned to
     * another workspace, the write went to the wrong team AND the read
     * followed it there — so the test passed green on data that was wrong,
     * and the failure only surfaced when the flow was run for real outside a
     * test (where it died with "There is no role named `owner`").
     *
     * A test whose read shares the bug with its write proves nothing.
     */
    public function test_the_owner_role_row_lands_on_the_new_workspace_not_the_founding_one(): void
    {
        $platformOwner = User::factory()->create(['platform_role' => 'platform_owner']);

        $this->asToken($platformOwner)->postJson('/api/platform/tenants', [
            'name' => 'Northwind', 'slug' => 'northwind', 'owner_email' => 'owner@northwind.test',
        ])->assertStatus(201);

        $created = Tenancy::asPlatform(fn () => Tenant::where('slug', 'northwind')->firstOrFail());
        $invitation = Tenancy::runAs($created, fn () => Invitation::firstOrFail());

        // Poison the team resolver with a stale override pointing at the
        // FOUNDING workspace — exactly the state workspace creation used to
        // leave behind. Acceptance must land on the new workspace anyway.
        //
        // THROUGH THE REGISTRAR, not app(TenantTeamResolver::class): the
        // registrar constructs its resolver with `new`, so the container
        // instance is a different object that Spatie never reads. Poisoning
        // that one sets nothing, and this test passed against the unfixed
        // code until it was pointed at the real one.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $user = app(InvitationService::class)
            ->accept($invitation, ['name' => 'New Owner', 'password' => 'password123']);

        $teamKey = config('permission.column_names.team_foreign_key', 'tenant_id');

        $rows = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)
            ->get(['roles.name', 'model_has_roles.'.$teamKey.' as team_id']);

        $this->assertCount(1, $rows, 'exactly one role assignment');
        $this->assertSame('owner', $rows[0]->name);
        $this->assertSame(
            $created->id,
            (int) $rows[0]->team_id,
            'the owner role was written against the wrong workspace',
        );

        // And the founding workspace gained nobody.
        $this->assertFalse(
            $this->tenant->users()->whereKey($user->id)->exists(),
            'the new owner must not have been added to the founding workspace',
        );
    }
}
