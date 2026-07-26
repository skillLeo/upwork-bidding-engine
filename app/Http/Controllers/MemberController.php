<?php

namespace App\Http\Controllers;

use App\Authorization\InvitationPolicy;
use App\Authorization\Permissions;
use App\Authorization\TenantRole;
use App\Enums\AuthEventType;
use App\Enums\UserRole;
use App\Models\AuthEvent;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Members\InvitationService;
use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Workspace membership: who is in the tenant, what role each holds, and the
 * invitation flow. Every action is scoped to the current tenant — a member
 * id or invitation id from another workspace simply is not found.
 */
class MemberController extends Controller
{
    public function __construct(
        protected InvitationService $invitations,
        protected InvitationPolicy $policy,
    ) {}

    /**
     * Active members plus pending/expired invitations, for the Members tab.
     */
    public function index(Request $request): JsonResponse
    {
        $tenant = Tenancy::current();

        $currentUserId = $request->user()?->id;

        $members = $tenant->users()->get()->map(function (User $user) use ($tenant, $currentUserId) {
            $role = Tenancy::runAs($tenant, fn () => $user->getRoleNames()->first());

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $role,
                'is_owner' => $user->id === $tenant->owner_user_id,
                'is_self' => $user->id === $currentUserId,
                'last_active_at' => $user->tokens()->max('last_used_at'),
                'status' => 'active',
            ];
        });

        $pending = Invitation::whereNull('accepted_at')->latest('id')->get()->map(fn (Invitation $i) => [
            'id' => $i->id,
            'email' => $i->email,
            'role' => $i->role,
            'status' => $i->expires_at->isPast() ? 'expired' : 'invited',
            'invited_at' => $i->created_at?->toIso8601String(),
        ]);

        return response()->json(['data' => [
            'members' => $members->values(),
            'invitations' => $pending->values(),
        ]]);
    }

    public function invite(Request $request): JsonResponse
    {
        // Validated against every role the enum knows, NOT against the
        // caller's allowed set — an out-of-matrix role must reach the policy
        // and come back as a logged 403, not as a 422 that reads like a typo.
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', Rule::in(TenantRole::values())],
        ]);

        // THE invitation matrix (P8): workspace owner → bidder/viewer only;
        // everybody else → nobody, whatever the payload says.
        $this->policy->assertMayInvite($request->user(), $validated['role']);

        $email = strtolower(trim($validated['email']));

        // Already a member? Nothing to invite.
        if (Tenancy::current()->users()->where('email', $email)->exists()) {
            return response()->json(['message' => 'That person is already a member.'], 422);
        }

        $this->invitations->invite(
            Tenancy::current(),
            $email,
            TenantRole::from($validated['role']),
            $request->user(),
        );

        return response()->json(['data' => ['message' => "Invitation sent to {$email}."]]);
    }

    public function resend(Request $request, Invitation $invitation): JsonResponse
    {
        if ($invitation->accepted_at !== null) {
            return response()->json(['message' => 'That invitation was already accepted.'], 422);
        }

        $this->invitations->resend(Tenancy::current(), $invitation, $request->user());

        return response()->json(['data' => ['message' => 'A fresh invitation is on its way.']]);
    }

    public function changeRole(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(TenantRole::values())],
        ]);

        $tenant = Tenancy::current();

        if (! $tenant->users()->whereKey($user->id)->exists()) {
            return response()->json(['message' => 'That person is not a member of this workspace.'], 404);
        }

        // The sole owner cannot be demoted — a workspace must always have
        // exactly one owner, and losing the last one would strand billing and
        // the delete-workspace control with nobody able to reach them.
        if ($tenant->owner_user_id === $user->id && $validated['role'] !== TenantRole::Owner->value) {
            return response()->json(['message' => 'Transfer ownership to someone else before changing the owner\'s role.'], 422);
        }

        // Same fixed matrix as inviting: bidder ↔ viewer, nothing else. The
        // owner role is reached by transfer, never by re-roling.
        $this->policy->assertMayAssign($request->user(), $validated['role']);

        Tenancy::runAs($tenant, fn () => $user->syncRoles([$validated['role']]));

        // Keep the legacy display column roughly in step for the profile
        // dropdown; authorization no longer reads it.
        $user->update(['role' => UserRole::Bidder->value]);

        AuthEvent::record(AuthEventType::RoleChanged, user: $user, request: $request);

        return response()->json(['data' => ['message' => "Role updated to {$validated['role']}."]]);
    }

    public function remove(Request $request, User $user): JsonResponse
    {
        $tenant = Tenancy::current();

        if ($tenant->owner_user_id === $user->id) {
            return response()->json(['message' => 'The owner cannot be removed. Transfer ownership first.'], 422);
        }

        if (! $tenant->users()->whereKey($user->id)->exists()) {
            return response()->json(['message' => 'That person is not a member of this workspace.'], 404);
        }

        // Detach from THIS workspace only — the user may belong to others.
        $tenant->users()->detach($user->id);

        // Revoke every token this user holds that was issued for this tenant,
        // so removal takes effect immediately rather than at their next
        // login. Tokens for other workspaces they belong to are untouched.
        $revoked = $user->tokens()->where('tenant_id', $tenant->id)->delete();

        Tenancy::runAs($tenant, fn () => $user->syncRoles([]));

        AuthEvent::record(AuthEventType::TokenRevoked, user: $user, request: $request);

        return response()->json(['data' => [
            'message' => "Removed from the workspace ({$revoked} session(s) ended).",
        ]]);
    }

    /**
     * The Roles matrix — EDITABLE since the 2026-07-24 product decision
     * (checkboxes for anyone holding permissions.edit; PermissionEditor
     * handles the writes). Grants come from the DATABASE, not the code
     * defaults, because each workspace can have edited its roles. Owner is
     * reported locked: always every permission, never editable.
     */
    public function rolesMatrix(Request $request): JsonResponse
    {
        // Grouped catalog: feature permissions by their prefix, and the
        // per-settings-key permissions by that key's settings GROUP, so the
        // matrix reads as "Leads / Proposals / ... / Setting keys: vollna".
        $catalog = [];

        foreach (Permissions::features() as $permission) {
            [$group] = explode('.', $permission, 2);
            $catalog[$group][] = $permission;
        }

        foreach (SettingsService::SCHEMA as $key => $meta) {
            $catalog['setting keys: '.$meta['group']][] = Permissions::forSettingKey($key);
        }

        $roles = array_map(function (TenantRole $roleEnum) {
            $locked = $roleEnum === TenantRole::Owner;

            // TENANCY: Spatie's Role query is team-scoped through
            // TenantTeamResolver, so this reads the CURRENT tenant's copy.
            $granted = $locked
                ? Permissions::all()
                : (Role::where('name', $roleEnum->value)->first()
                    ?->permissions->pluck('name')->values()->all() ?? []);

            return [
                'value' => $roleEnum->value,
                'label' => $roleEnum->label(),
                'description' => $roleEnum->description(),
                'locked' => $locked,
                'granted' => $granted,
            ];
        }, TenantRole::cases());

        return response()->json(['data' => [
            'groups' => $catalog,
            'roles' => $roles,
            'can_edit' => (bool) $request->user()?->can(Permissions::PERMISSIONS_EDIT),
        ]]);
    }
}
