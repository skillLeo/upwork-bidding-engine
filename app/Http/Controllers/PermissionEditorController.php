<?php

namespace App\Http\Controllers;

use App\Authorization\Permissions;
use App\Authorization\TenantRole;
use App\Enums\AuthEventType;
use App\Models\AuthEvent;
use App\Models\PermissionDeny;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The write side of the editable permission system. Route-gated by
 * permissions.edit — itself a permission, so the owner decides who holds it.
 *
 * Two hard limits that no holder of permissions.edit can cross:
 *   - the OWNER ROLE is untouchable (always every permission), and
 *   - the OWNER USER cannot be given overrides or denies.
 * Together they guarantee the workspace can always be repaired, no matter
 * how badly the editable surface is misconfigured.
 */
class PermissionEditorController extends Controller
{
    /**
     * Replace a role's grants wholesale (the matrix saves the full column).
     */
    public function updateRole(Request $request, string $role): JsonResponse
    {
        $validated = $request->validate([
            'granted' => ['present', 'array'],
            'granted.*' => ['string', Rule::in(Permissions::all())],
        ]);

        $roleEnum = TenantRole::tryFrom($role);

        if ($roleEnum === null) {
            return response()->json(['message' => 'Unknown role.'], 404);
        }

        if ($roleEnum === TenantRole::Owner) {
            return response()->json([
                'message' => 'The Owner role is locked at full access and cannot be edited.',
            ], 422);
        }

        // TENANCY: Spatie roles are team-scoped via TenantTeamResolver, so
        // this edits only the current workspace's copy of the role.
        $model = Role::where('name', $roleEnum->value)->first();

        if ($model === null) {
            return response()->json(['message' => 'That role is not provisioned for this workspace.'], 404);
        }

        $model->syncPermissions(array_values(array_unique($validated['granted'])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        AuthEvent::record(AuthEventType::RoleChanged, user: $request->user(), request: $request);

        return response()->json(['data' => [
            'message' => ucfirst($roleEnum->value).' role updated ('.count($validated['granted']).' permission(s)).',
            'granted' => $model->fresh()->permissions->pluck('name')->values(),
        ]]);
    }

    /**
     * A member's current override state: what their role gives them, plus
     * their personal extra grants and denies.
     */
    public function showOverrides(Request $request, User $user): JsonResponse
    {
        $tenant = Tenancy::current();

        if (! $tenant->users()->whereKey($user->id)->exists()) {
            return response()->json(['message' => 'That person is not a member of this workspace.'], 404);
        }

        return response()->json(['data' => [
            'is_owner' => $tenant->owner_user_id === $user->id,
            'role' => $user->getRoleNames()->first(),
            'from_role' => $user->getPermissionsViaRoles()->pluck('name')->values(),
            'grants' => $user->getDirectPermissions()->pluck('name')->values(),
            'denies' => array_values(PermissionDeny::forUser($user->id)),
            'effective' => $user->effectivePermissions(),
        ]]);
    }

    /**
     * Replace a member's personal grants and denies. Grants add on top of
     * the role; denies subtract from it — a deny of something also granted
     * resolves to DENIED (Gate::before runs first).
     */
    public function updateOverrides(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'grants' => ['present', 'array'],
            'grants.*' => ['string', Rule::in(Permissions::all())],
            'denies' => ['present', 'array'],
            'denies.*' => ['string', Rule::in(Permissions::all())],
        ]);

        $tenant = Tenancy::current();

        if (! $tenant->users()->whereKey($user->id)->exists()) {
            return response()->json(['message' => 'That person is not a member of this workspace.'], 404);
        }

        if ($tenant->owner_user_id === $user->id) {
            return response()->json([
                'message' => 'The owner always has full access — overrides cannot be applied to them.',
            ], 422);
        }

        $user->syncPermissions(array_values(array_unique($validated['grants'])));

        // Replace the deny set wholesale, mirroring the grants semantics.
        PermissionDeny::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->delete();

        foreach (array_unique($validated['denies']) as $permission) {
            PermissionDeny::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'permission' => $permission,
                'created_at' => now(),
            ]);
        }

        PermissionDeny::flushRequestCache();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        AuthEvent::record(AuthEventType::RoleChanged, user: $user, request: $request);

        return response()->json(['data' => [
            'message' => "Overrides saved for {$user->name}.",
            'effective' => $user->fresh()->effectivePermissions(),
        ]]);
    }
}
