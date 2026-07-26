<?php

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repoint access tokens stamped with a workspace their owner does not belong
 * to.
 *
 * Sign-in happens on the public host, which names no workspace, so the
 * request fell back to the configured default — workspace 1 — and TokenIssuer
 * stamped THAT on the token. Every later request then resolved the workspace
 * from the token and landed in the founder's workspace, where a second
 * workspace's owner holds no role: empty navigation, every settings page
 * refused, and that workspace's saved-filter names visible.
 *
 * The login path now binds a workspace the person actually belongs to, so new
 * tokens are correct. This repairs the ones already issued, so nobody has to
 * work out that signing out and back in is the cure.
 *
 * Only tokens whose stamped workspace is demonstrably WRONG are touched — the
 * user is not a member of it. A token pointing at a workspace they do belong
 * to is left exactly as it is, including anyone deliberately working in a
 * second workspace they share.
 */
return new class extends Migration
{
    public function up(): void
    {
        // TENANCY: repairing token rows across every workspace is the job;
        // personal_access_tokens is deliberately outside the global scope.
        Tenancy::asPlatform(function () {
            $tokens = DB::table('personal_access_tokens')
                ->whereNotNull('tenant_id')
                ->orderBy('id')
                ->get(['id', 'tokenable_id', 'tokenable_type', 'tenant_id', 'name']);

            if ($tokens->isEmpty()) {
                echo '  No tokens to check.'.PHP_EOL;

                return;
            }

            $membership = DB::table('tenant_users')->get(['user_id', 'tenant_id'])
                ->groupBy('user_id')
                ->map(fn ($rows) => $rows->pluck('tenant_id')->all());

            $owned = Tenant::withTrashed()->whereNotNull('owner_user_id')
                ->pluck('id', 'owner_user_id');

            $repointed = 0;
            $revoked = 0;

            foreach ($tokens as $token) {
                if ($token->tokenable_type !== User::class) {
                    continue;
                }

                $theirs = $membership[$token->tokenable_id] ?? [];

                if (in_array((int) $token->tenant_id, array_map('intval', $theirs), true)) {
                    continue; // Already correct.
                }

                // Prefer a workspace they own, else any they belong to.
                $target = $owned[$token->tokenable_id] ?? ($theirs[0] ?? null);

                if ($target === null) {
                    // They belong to no workspace at all. The token cannot be
                    // pointed anywhere honest, and leaving it aimed at a
                    // workspace they are not in is the bug itself — so it goes.
                    DB::table('personal_access_tokens')->where('id', $token->id)->delete();
                    $revoked++;

                    echo sprintf('  token #%d (user %d): user belongs to no workspace — revoked%s',
                        $token->id, $token->tokenable_id, PHP_EOL);

                    continue;
                }

                DB::table('personal_access_tokens')
                    ->where('id', $token->id)
                    ->update(['tenant_id' => $target]);

                $repointed++;

                echo sprintf('  token #%d (user %d): workspace %d -> %d%s',
                    $token->id, $token->tokenable_id, $token->tenant_id, $target, PHP_EOL);
            }

            echo PHP_EOL."  Repointed: {$repointed}. Revoked: {$revoked}. Left alone: "
                .($tokens->count() - $repointed - $revoked).'.'.PHP_EOL;
        });
    }

    public function down(): void
    {
        // Not reversed: the previous values were wrong, which is the whole
        // reason this ran.
    }
};
