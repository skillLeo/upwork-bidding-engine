<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Delete the tenant-scoped 'admin' role (P8).
 *
 * The final hierarchy is owner / bidder / viewer. What earlier drafts called
 * an "admin" is the OWNER of their own workspace, not a deputy inside someone
 * else's — so the role has no meaning any more, and leaving a dead role in
 * the Roles matrix invites somebody to assign it.
 *
 * IT ASSERTS BEFORE IT DELETES. If any user still holds the role the
 * migration aborts and prints exactly who, because the alternative — guessing
 * whether they should land on bidder or viewer — silently changes what a real
 * person can do in a real workspace. That is the operator's call, not this
 * file's. Expected count when this ships is zero (verified against production
 * before writing it: zero holders).
 */
return new class extends Migration
{
    public function up(): void
    {
        $teamKey = config('permission.column_names.team_foreign_key', 'tenant_id');

        $holders = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->leftJoin('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('roles.name', 'admin')
            ->select([
                'model_has_roles.model_id',
                'model_has_roles.'.$teamKey.' as team_id',
                'users.email',
            ])
            ->get();

        if ($holders->isNotEmpty()) {
            $lines = $holders->map(fn ($h) => sprintf(
                '  - user #%s <%s> in tenant %s',
                $h->model_id,
                $h->email ?? 'unknown email',
                $h->team_id ?? 'unknown',
            ))->implode(PHP_EOL);

            throw new RuntimeException(
                'Refusing to delete the tenant admin role: '.$holders->count().' user(s) still hold it.'.PHP_EOL
                .$lines.PHP_EOL
                .'Re-role each of them to bidder or viewer first, then run this migration again. '
                .'This migration will not guess which one they should become.'
            );
        }

        $roleIds = DB::table('roles')->where('name', 'admin')->pluck('id');

        if ($roleIds->isEmpty()) {
            echo "  No tenant 'admin' role found — nothing to remove.".PHP_EOL;

            return;
        }

        $grants = DB::table('role_has_permissions')->whereIn('role_id', $roleIds)->count();

        DB::table('role_has_permissions')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->whereIn('id', $roleIds)->delete();

        echo sprintf(
            "  Removed %d tenant 'admin' role row(s) and %d permission grant(s). Tenant roles are now owner/bidder/viewer.%s",
            $roleIds->count(),
            $grants,
            PHP_EOL,
        );
    }

    public function down(): void
    {
        // Deliberately irreversible. Recreating the row would produce a role
        // with no permissions and no meaning in the current hierarchy, which
        // is worse than its absence — and nobody held it, so nothing was lost.
    }
};
