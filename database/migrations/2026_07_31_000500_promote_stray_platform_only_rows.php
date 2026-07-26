<?php

use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Promote every platform-only key that is still sitting in a tenant row.
 *
 * WHY THIS EXISTS. config/tenancy.php has declared keys platform-only since
 * P1, but that declaration only governs FUTURE writes — and even then, a
 * write from the platform-owning workspace is REDIRECTED to the platform
 * layer rather than refused (see SettingsService::writeTenantId). Rows
 * written before a key joined the list simply stayed where they were.
 *
 * Found on production immediately after the P8 deploy: all seven mail_* rows
 * were tenant-scoped. Mail still worked, because SettingsService::get()
 * resolves "this tenant OR platform" and puts the tenant's own row last. But
 * P8 moved the mail form into the platform console, which reads and writes
 * the PLATFORM layer explicitly — so the form would have shown blank fields,
 * and saving it would have written a second, shadowed row while the live
 * tenant row kept winning. A settings screen that appears to save and
 * silently does nothing is worse than one that is missing.
 *
 * Deliberately generic rather than a mail_* special case: the same latent
 * split can exist for any key added to platform_only_keys after its rows
 * were created, and enumerating today's offenders would leave tomorrow's.
 *
 * THE TENANT ROW WINS on a conflict. It is the value get() resolves today,
 * so promoting it is the change that keeps behaviour identical; taking the
 * platform row instead would silently swap live credentials.
 */
return new class extends Migration
{
    public function up(): void
    {
        $keys = (array) config('tenancy.platform_only_keys', []);

        // TENANCY: consolidating tenant rows into platform rows is inherently
        // cross-tenant — that is the operation, not a bypass of it.
        Tenancy::asPlatform(function () use ($keys) {
            $strays = DB::table('settings')
                ->whereIn('key', $keys)
                ->whereNotNull('tenant_id')
                ->orderBy('key')
                ->get();

            if ($strays->isEmpty()) {
                echo '  No stray tenant-scoped platform keys — nothing to promote.'.PHP_EOL;

                return;
            }

            $promoted = 0;
            $replaced = 0;

            foreach ($strays as $row) {
                $platformRow = DB::table('settings')
                    ->where('key', $row->key)
                    ->whereNull('tenant_id')
                    ->first();

                if ($platformRow === null) {
                    // Promote in place: `value` is ciphertext for the secret
                    // keys, and re-encrypting is a needless chance to lose one.
                    DB::table('settings')->where('id', $row->id)->update([
                        'tenant_id' => null,
                        'updated_at' => now(),
                    ]);

                    echo "  {$row->key}: tenant row #{$row->id} promoted (tenant {$row->tenant_id} -> platform).".PHP_EOL;
                    $promoted++;

                    continue;
                }

                // Both exist. The TENANT row is the one get() resolves today,
                // so it is the live value and it must be the one that survives.
                DB::table('settings')->where('id', $platformRow->id)->update([
                    'value' => $row->value,
                    'is_secret' => $row->is_secret,
                    'updated_at' => now(),
                ]);
                DB::table('settings')->where('id', $row->id)->delete();

                echo "  {$row->key}: live tenant row #{$row->id} copied over shadowed platform row #{$platformRow->id}.".PHP_EOL;
                $replaced++;
            }

            $remaining = DB::table('settings')->whereIn('key', $keys)->whereNotNull('tenant_id')->count();

            echo PHP_EOL."  Promoted: {$promoted}. Replaced: {$replaced}. Tenant-scoped platform keys remaining: {$remaining}.".PHP_EOL;

            if ($remaining !== 0) {
                throw new RuntimeException("Expected zero tenant-scoped platform keys, found {$remaining}.");
            }
        });

        app(SettingsService::class)->forgetAllCaches();
    }

    public function down(): void
    {
        // Not reversed: pushing a platform credential back into one tenant's
        // row is the split this migration exists to close.
    }
};
