<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * get() and platform() must agree for every platform-only key.
 *
 * They can disagree, and on production they did: the mail_* rows predated
 * their key joining platform_only_keys, so they sat on tenant 1. get()
 * resolved them ("this tenant OR platform"), platform() did not, and the
 * platform console — which reads and writes the platform layer explicitly —
 * would have shown blank fields and saved into a shadowed row while live
 * mail kept using the old one.
 *
 * This is the regression test for that split.
 */
class PlatformLayerConsistencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run the promotion migration's up() directly.
     *
     * NOT `artisan migrate --path`: RefreshDatabase has already run every
     * migration, so the runner finds this one in the migrations table and
     * exits 0 having done nothing — which looks exactly like a pass.
     */
    private function runPromotion(): void
    {
        $migration = require database_path('migrations/2026_07_31_000500_promote_stray_platform_only_rows.php');
        $migration->up();
    }

    public function test_no_platform_only_key_can_be_left_in_a_tenant_row(): void
    {
        $keys = (array) config('tenancy.platform_only_keys');
        $this->assertNotEmpty($keys);

        // Plant the exact production defect: a tenant-scoped row for a
        // platform-only key. Inserted straight into the table, because that
        // is the only way one can exist — the service refuses or redirects
        // such a write today, and tenant_id is not mass-assignable on the
        // model precisely so a request can never choose its own layer.
        DB::table('settings')->insert([
            'tenant_id' => $this->tenant->id,
            'key' => 'mail_host',
            'value' => json_encode('smtp.live-value.test'),
            'group' => 'mail',
            'is_secret' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(SettingsService::class)->forgetAllCaches();

        $settings = app(SettingsService::class);

        // The split, demonstrated before it is closed.
        $this->assertSame('smtp.live-value.test', $settings->get('mail_host'));
        $this->assertSame('', $settings->platform('mail_host'));

        $this->runPromotion();

        app(SettingsService::class)->forgetAllCaches();

        // Closed: both paths now return the value mail actually uses.
        $this->assertSame('smtp.live-value.test', $settings->get('mail_host'));
        $this->assertSame('smtp.live-value.test', $settings->platform('mail_host'));

        $strays = Tenancy::asPlatform(fn () => Setting::withoutGlobalScopes()
            ->whereIn('key', $keys)->whereNotNull('tenant_id')->count());
        $this->assertSame(0, $strays);
    }

    public function test_the_live_tenant_value_wins_over_a_shadowed_platform_row(): void
    {
        // A platform row AND a tenant row for the same key. get() resolves
        // the tenant one, so that is the value in live use and the one that
        // must survive — taking the platform row would swap credentials.
        DB::table('settings')->insert([
            [
                'tenant_id' => null,
                'key' => 'mail_password',
                'value' => Crypt::encryptString(json_encode('shadowed-and-stale')),
                'group' => 'mail',
                'is_secret' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->tenant->id,
                'key' => 'mail_password',
                'value' => Crypt::encryptString(json_encode('the-live-password')),
                'group' => 'mail',
                'is_secret' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        app(SettingsService::class)->forgetAllCaches();
        $this->assertSame('the-live-password', app(SettingsService::class)->get('mail_password'));

        $this->runPromotion();

        app(SettingsService::class)->forgetAllCaches();

        $this->assertSame('the-live-password', app(SettingsService::class)->platform('mail_password'));
        $this->assertSame(
            1,
            Tenancy::asPlatform(fn () => Setting::withoutGlobalScopes()->where('key', 'mail_password')->count()),
            'exactly one row survives',
        );
    }
}
