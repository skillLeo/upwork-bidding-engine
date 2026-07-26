<?php

use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move the lead-source credentials to the platform layer.
 *
 * Vollna and the IMAP intake mailbox are the PRODUCT's plumbing, not a
 * customer's configuration: one subscription feeds the whole platform, the
 * webhook secret protects one endpoint, and the mailbox is one inbox. A
 * workspace was never going to supply its own, and leaving the keys
 * tenant-scoped meant every workspace owner could read the masked value of a
 * credential that isn't theirs and point intake somewhere else.
 *
 * Same shape as the AI-credential move: the founding workspace's values
 * become the platform rows, every tenant-scoped copy is deleted, and any
 * OTHER workspace holding a value for these keys aborts the migration rather
 * than being silently discarded.
 */
return new class extends Migration
{
    private const KEYS = [
        'vollna_webhook_secret',
        'vollna_api_token',
        'vollna_filter_id',
        'vollna_silence_alert_hours',
        'gmail_address',
        'gmail_app_password',
        'imap_host',
        'imap_port',
        'imap_folder',
        'vollna_sender_filter',
        'imap_poll_enabled',
    ];

    public function up(): void
    {
        // TENANCY: this rehomes rows across every workspace by design.
        Tenancy::asPlatform(function () {
            $founding = DB::table('tenants')->where('plan', 'internal')->orderBy('id')->first();

            if ($founding === null) {
                echo '  No platform-owning workspace — nothing to promote.'.PHP_EOL;

                return;
            }

            // Anyone ELSE holding one of these has entered a credential of
            // their own. Discarding it silently would be the wrong call, so
            // stop and say whose it is.
            $strangers = DB::table('settings')
                ->whereIn('key', self::KEYS)
                ->whereNotNull('tenant_id')
                ->where('tenant_id', '!=', $founding->id)
                ->get(['id', 'key', 'tenant_id']);

            if ($strangers->isNotEmpty()) {
                foreach ($strangers as $row) {
                    echo "  workspace {$row->tenant_id} holds [{$row->key}] (row {$row->id})".PHP_EOL;
                }

                throw new RuntimeException(
                    'Another workspace has its own lead-source credentials. Decide what happens to '
                    .'them before promoting these keys — this migration will not discard them.'
                );
            }

            $moved = 0;
            $deleted = 0;

            foreach (self::KEYS as $key) {
                $row = DB::table('settings')->where('key', $key)->where('tenant_id', $founding->id)->first();

                if ($row === null) {
                    continue;
                }

                $platform = DB::table('settings')->where('key', $key)->whereNull('tenant_id')->first();

                if ($platform === null) {
                    DB::table('settings')->where('id', $row->id)
                        ->update(['tenant_id' => null, 'updated_at' => now()]);
                    $moved++;

                    echo "  {$key}: tenant {$founding->id} -> platform".PHP_EOL;

                    continue;
                }

                // A platform row already exists; the tenant copy is the newer
                // truth (it is what the app has been reading), so it wins.
                DB::table('settings')->where('id', $platform->id)->update([
                    'value' => $row->value,
                    'is_secret' => $row->is_secret,
                    'updated_at' => now(),
                ]);
                DB::table('settings')->where('id', $row->id)->delete();
                $moved++;
                $deleted++;

                echo "  {$key}: tenant copy folded into the existing platform row".PHP_EOL;
            }

            $remaining = DB::table('settings')->whereIn('key', self::KEYS)->whereNotNull('tenant_id')->count();
            $platformRows = DB::table('settings')->whereIn('key', self::KEYS)->whereNull('tenant_id')->count();

            echo PHP_EOL."  Moved: {$moved} (of which {$deleted} folded). ".PHP_EOL;
            echo "  Platform rows now: {$platformRows}. Tenant-scoped copies remaining: {$remaining}.".PHP_EOL;
        });

        app(SettingsService::class)->forgetAllCaches();
    }

    public function down(): void
    {
        // Not reversed: handing a customer workspace the platform's lead-source
        // credentials back is the state this exists to remove.
    }
};
