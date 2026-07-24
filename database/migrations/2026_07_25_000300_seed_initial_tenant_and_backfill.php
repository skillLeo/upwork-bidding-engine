<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adopts every existing row into tenant 1, then tightens tenant_id to NOT
 * NULL so nothing can be written ownerless afterwards.
 *
 * Guard rail: if a table has rows but none were updated, this ABORTS by
 * throwing rather than continuing. A partially-adopted database is worse
 * than a failed migration — the orphaned rows would be invisible to every
 * tenant and would look, from the UI, exactly like data loss.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private const TABLES = [
        'leads',
        'proposal_versions',
        'clients',
        'messages',
        'activity_logs',
        'ai_calls',
        'app_notifications',
        'push_subscriptions',
        'saved_filters',
        'templates',
        'settings',
        'deleted_lead_external_ids',
    ];

    public function up(): void
    {
        $tenantId = DB::table('tenants')->where('slug', 'skillleo')->value('id');

        if ($tenantId === null) {
            $tenantId = DB::table('tenants')->insertGetId([
                'name' => 'SkillLeo',
                'slug' => 'skillleo',
                'plan' => 'internal',
                'status' => 'active',
                'owner_user_id' => null,
                'trial_ends_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $report = [];

        foreach (self::TABLES as $table) {
            $before = DB::table($table)->whereNull('tenant_id')->count();
            $updated = $before > 0
                ? DB::table($table)->whereNull('tenant_id')->update(['tenant_id' => $tenantId])
                : 0;

            $total = DB::table($table)->count();

            if ($total > 0 && $updated === 0 && $before > 0) {
                throw new RuntimeException(
                    "Backfill aborted: {$table} has {$total} rows and {$before} unowned, but 0 were updated."
                );
            }

            $report[] = sprintf('  %-28s %5d row(s) adopted, %d total', $table, $updated, $total);
        }

        // Every existing user belongs to the one existing workspace.
        $userIds = DB::table('users')->pluck('id');
        foreach ($userIds as $userId) {
            DB::table('tenant_users')->updateOrInsert(
                ['tenant_id' => $tenantId, 'user_id' => $userId],
                ['joined_at' => now()],
            );
        }
        $report[] = sprintf('  %-28s %5d attached', 'tenant_users', $userIds->count());

        // Owner is the existing admin. Left null if there isn't one rather
        // than picking an arbitrary user to hold billing rights later.
        $adminId = DB::table('users')->where('role', 'admin')->orderBy('id')->value('id');
        if ($adminId !== null) {
            DB::table('tenants')->where('id', $tenantId)->update(['owner_user_id' => $adminId]);
        }
        $report[] = sprintf('  %-28s %s', 'owner_user_id', $adminId ?? 'none (no admin user found)');

        // Now that nothing is unowned, make ownerless rows impossible.
        // settings is the deliberate exception: tenant_id NULL there is a
        // real, meaningful value — the platform default layer.
        foreach (self::TABLES as $table) {
            if ($table === 'settings') {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('tenant_id')->nullable(false)->change();
            });
        }

        if (app()->runningInConsole()) {
            echo PHP_EOL.'Tenant backfill (tenant_id='.$tenantId.'):'.PHP_EOL
                .implode(PHP_EOL, $report).PHP_EOL.PHP_EOL;
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if ($table === 'settings') {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('tenant_id')->nullable()->change();
            });
        }

        // The rows keep their tenant_id — reversing the schema change should
        // not silently orphan real data. Dropping the column entirely is the
        // previous migration's job.
        DB::table('tenant_users')->delete();
        DB::table('tenants')->where('slug', 'skillleo')->delete();
    }
};
