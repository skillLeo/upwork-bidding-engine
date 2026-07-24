<?php

namespace App\Http\Controllers;

use App\Authorization\Permissions;
use App\Authorization\TenantRole;
use App\Enums\AuthEventType;
use App\Models\AuthEvent;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Members\InvitationService;
use App\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Workspace membership: who is in the tenant, what role each holds, and the
 * invitation flow. Every action is scoped to the current tenant — a member
 * id or invitation id from another workspace simply is not found.
 */
class MemberController extends Controller
{
    public function __construct(protected InvitationService $invitations) {}

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
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', Rule::in(TenantRole::values())],
        ]);

        // Only an owner may mint another owner — otherwise an admin could
        // grant a co-owner and hand out billing/delete powers they don't
        // themselves fully control.
        if ($validated['role'] === TenantRole::Owner->value && ! $request->user()->hasRole(TenantRole::Owner->value)) {
            abort(403, 'Only an owner can invite another owner.');
        }

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

        if ($validated['role'] === TenantRole::Owner->value && ! $request->user()->hasRole(TenantRole::Owner->value)) {
            abort(403, 'Only an owner can grant the owner role.');
        }

        Tenancy::runAs($tenant, fn () => $user->syncRoles([$validated['role']]));

        // Keep the legacy display column roughly in step for the profile
        // dropdown; authorization no longer reads it.
        $user->update(['role' => $validated['role'] === TenantRole::Admin->value ? 'admin' : 'bidder']);

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
     * The read-only Roles matrix: every role, every permission, and which
     * cells are checked. Read-only by design — custom roles are a paid
     * feature for the day a customer asks, not a builder shipped up front.
     */
    public function rolesMatrix(): JsonResponse
    {
        $permissions = Permissions::all();

        $roles = array_map(fn (TenantRole $role) => [
            'value' => $role->value,
            'label' => $role->label(),
            'description' => $role->description(),
            'granted' => array_values(array_intersect($permissions, $role->permissions())),
        ], TenantRole::cases());

        return response()->json(['data' => [
            'permissions' => $permissions,
            'roles' => $roles,
        ]]);
    }
}
