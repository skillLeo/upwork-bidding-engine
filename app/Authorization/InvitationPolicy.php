<?php

namespace App\Authorization;

use App\Models\ActivityLog;
use App\Models\User;
use App\Tenancy\Tenancy;

/**
 * WHO MAY INVITE WHOM (P8) — enforced here, on the server, for every path.
 *
 *   platform owner  → creates workspaces; the creation flow invites that
 *                     workspace's owner by email. This is the ONLY way an
 *                     owner invitation is ever minted.
 *   workspace owner → bidder, viewer. Nothing else, ever.
 *   bidder, viewer  → nobody. 403 regardless of payload.
 *
 * This is a class and not just a hidden <option> because the UI is not a
 * security boundary: the invite endpoint is a plain authenticated POST, and
 * anyone who can read the network tab can send role=owner to it. The modal
 * showing two choices is a courtesy; THIS is the rule.
 *
 * It is also deliberately NOT expressed as a permission. Permissions in this
 * app are editable per workspace (the Roles matrix), so an owner who ticked
 * members.invite for the bidder role would otherwise have quietly handed
 * bidders the ability to grow the workspace. The matrix below is fixed in
 * code and no amount of permission editing can widen it.
 */
class InvitationPolicy
{
    /**
     * The roles $inviter may invite into the CURRENT workspace.
     *
     * @return array<int, TenantRole>
     */
    public function allowedRoles(User $inviter): array
    {
        return $this->isWorkspaceOwner($inviter) ? TenantRole::invitable() : [];
    }

    /**
     * @return array<int, string>
     */
    public function allowedRoleValues(User $inviter): array
    {
        return array_map(fn (TenantRole $r) => $r->value, $this->allowedRoles($inviter));
    }

    /**
     * Refuse anything outside the matrix with a 403, and leave a trace.
     *
     * The refusal is logged BEFORE aborting, because an attempt to invite an
     * owner is exactly the event worth being able to find later — the UI
     * never offers it, so a request carrying role=owner was hand-crafted.
     */
    public function assertMayInvite(User $inviter, string $role): void
    {
        $allowed = $this->allowedRoleValues($inviter);

        if (in_array($role, $allowed, true)) {
            return;
        }

        ActivityLog::record('invitation_refused', meta: [
            'attempted_role' => $role,
            'inviter_user_id' => $inviter->id,
            'inviter_role' => $this->roleIn($inviter) ?? 'none',
            'allowed_roles' => $allowed,
        ], userId: $inviter->id);

        abort(403, $allowed === []
            ? 'Only the workspace owner can invite people to this workspace.'
            : 'A workspace owner can invite a bidder or a viewer, nothing else.');
    }

    /**
     * Re-roling an existing member is the same gate as inviting one: an
     * owner may move somebody between bidder and viewer and nothing more.
     * Promoting to owner is ownership TRANSFER, which has its own flow.
     */
    public function assertMayAssign(User $actor, string $role): void
    {
        $allowed = $this->allowedRoleValues($actor);

        if (in_array($role, $allowed, true)) {
            return;
        }

        ActivityLog::record('role_assignment_refused', meta: [
            'attempted_role' => $role,
            'actor_user_id' => $actor->id,
            'actor_role' => $this->roleIn($actor) ?? 'none',
            'allowed_roles' => $allowed,
        ], userId: $actor->id);

        abort(403, $role === TenantRole::Owner->value
            ? 'Use Transfer ownership — the owner role cannot be assigned directly.'
            : 'Only the workspace owner can change a member\'s role, and only between bidder and viewer.');
    }

    private function isWorkspaceOwner(User $user): bool
    {
        $tenant = Tenancy::current();

        // owner_user_id is the authoritative answer (it is what every
        // ownership-locked control in the app already keys off); the Spatie
        // role is the fallback for the moment before it is stamped.
        if ($tenant !== null && (int) $tenant->owner_user_id === (int) $user->id) {
            return true;
        }

        return $this->roleIn($user) === TenantRole::Owner->value;
    }

    private function roleIn(User $user): ?string
    {
        return $user->getRoleNames()->first();
    }
}
