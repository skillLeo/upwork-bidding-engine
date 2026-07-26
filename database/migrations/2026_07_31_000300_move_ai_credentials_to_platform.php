<?php

use App\Models\Tenant;
use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move the AI provider credentials and model choices from tenant custody to
 * platform custody (P8).
 *
 * The product now pays for AI centrally out of one pooled set of keys, and
 * governs per-workspace spend with the ai_monthly_token_cap quota built in
 * P5. A workspace therefore never sees, enters, or holds a provider key — and
 * cannot pick a model either, since choosing the most expensive one available
 * is the same hole approached from the other side.
 *
 * The founding workspace's current values become the platform values; every
 * tenant-scoped copy is then deleted, so the only row that can exist for
 * these keys is the platform one (SettingsService::writeTenantId throws on
 * any later attempt to write a tenant override).
 *
 * IT ABORTS RATHER THAN DISCARDING. If a workspace OTHER than the
 * platform-owning one holds a value for these keys, that is a real credential
 * belonging to a real customer, and deleting it silently would break their
 * scoring with no trace of why. Expected today: none exist (verified against
 * production — all six rows belong to tenant 1, the internal workspace).
 */
return new class extends Migration
{
    private const KEYS = [
        'ai_provider',
        'anthropic_api_key',
        'openai_api_key',
        'scoring_model',
        'proposal_model',
        'review_model',
    ];

    public function up(): void
    {
        // TENANCY: this migration is by definition a cross-tenant operation —
        // it is consolidating every tenant's copy of a key into one platform
        // row. Reading as platform is the point, not a bypass.
        Tenancy::asPlatform(function () {
            $sourceTenant = Tenant::withTrashed()->where('plan', 'internal')->orderBy('id')->first()
                ?? Tenant::withTrashed()->orderBy('id')->first();

            if ($sourceTenant === null) {
                echo '  No tenants exist — nothing to move.'.PHP_EOL;

                return;
            }

            $strays = DB::table('settings')
                ->whereIn('key', self::KEYS)
                ->whereNotNull('tenant_id')
                ->where('tenant_id', '!=', $sourceTenant->id)
                ->get(['key', 'tenant_id']);

            if ($strays->isNotEmpty()) {
                $lines = $strays->map(fn ($s) => "  - {$s->key} on tenant {$s->tenant_id}")->implode(PHP_EOL);

                throw new RuntimeException(
                    'Refusing to move AI credentials to the platform layer: '.$strays->count()
                    .' value(s) belong to a workspace other than the platform-owning one ('
                    .$sourceTenant->slug.', id '.$sourceTenant->id.'):'.PHP_EOL
                    .$lines.PHP_EOL
                    .'Those are somebody\'s real credentials. Decide what happens to them before re-running.'
                );
            }

            echo "  Source workspace: {$sourceTenant->slug} (id {$sourceTenant->id})".PHP_EOL;

            $moved = 0;

            foreach (self::KEYS as $key) {
                $row = DB::table('settings')
                    ->where('key', $key)
                    ->where('tenant_id', $sourceTenant->id)
                    ->first();

                if ($row === null) {
                    echo "  {$key}: no tenant row — platform default already applies.".PHP_EOL;

                    continue;
                }

                $existingPlatform = DB::table('settings')->where('key', $key)->whereNull('tenant_id')->first();

                if ($existingPlatform === null) {
                    // Promote the tenant row itself rather than copying and
                    // deleting: the value column is encrypted for the secret
                    // keys, and re-encrypting is a needless chance to lose one.
                    DB::table('settings')->where('id', $row->id)->update([
                        'tenant_id' => null,
                        'updated_at' => now(),
                    ]);

                    echo "  {$key}: tenant row #{$row->id} promoted to the platform layer.".PHP_EOL;
                } else {
                    DB::table('settings')->where('id', $existingPlatform->id)->update([
                        'value' => $row->value,
                        'is_secret' => $row->is_secret,
                        'updated_at' => now(),
                    ]);

                    DB::table('settings')->where('id', $row->id)->delete();

                    echo "  {$key}: platform row #{$existingPlatform->id} updated, tenant row #{$row->id} deleted.".PHP_EOL;
                }

                $moved++;
            }

            $remaining = DB::table('settings')->whereIn('key', self::KEYS)->whereNotNull('tenant_id')->count();
            $platformRows = DB::table('settings')->whereIn('key', self::KEYS)->whereNull('tenant_id')->count();

            echo PHP_EOL."  Moved: {$moved}. Platform rows now: {$platformRows}. Tenant-scoped copies remaining: {$remaining}.".PHP_EOL;

            if ($remaining !== 0) {
                throw new RuntimeException("Expected zero tenant-scoped copies after the move, found {$remaining}.");
            }
        });

        // Every cached settings payload now holds a stale copy of these keys.
        app(SettingsService::class)->forgetAllCaches();
    }

    public function down(): void
    {
        // Not reversed. Pushing pooled platform credentials back down into a
        // tenant row would hand one workspace the keys every workspace's
        // calls are billed to — the exact custody failure this migration
        // exists to end.
    }
};
