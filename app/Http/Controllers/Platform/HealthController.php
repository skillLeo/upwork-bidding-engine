<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AiCall;
use App\Models\Tenant;
use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Platform health (P5): the cross-tenant view of the same primitives
 * DiagnosticsController already exposes per-tenant, plus AI error rate by
 * provider and which tenants' Vollna intake has gone silent.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $since = now()->subHours(24);

        // TENANCY: AI error rate by provider is a platform-wide aggregate,
        // deliberately across every tenant.
        $errorRates = Tenancy::asPlatform(fn () => AiCall::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('provider, count(*) total, sum(case when success then 0 else 1 end) failed')
            ->groupBy('provider')->get())
            ->map(fn ($row) => [
                'provider' => $row->provider,
                'total_calls' => (int) $row->total,
                'failed_calls' => (int) $row->failed,
                'error_rate_pct' => $row->total > 0 ? round(($row->failed / $row->total) * 100, 1) : 0.0,
            ])->values();

        // TENANCY: scanning every non-suspended tenant's own Vollna intake
        // timestamp for the silent-intake tile.
        $silentTenants = Tenancy::asPlatform(fn () => Tenant::query()
            ->where('status', '!=', Tenant::STATUS_SUSPENDED)
            ->get()
            ->filter(fn (Tenant $t) => Tenancy::runAs($t, function () {
                $settings = app(SettingsService::class);
                $lastIntake = $settings->get('vollna_last_intake_at');
                $silenceHours = max(1, (int) $settings->get('vollna_silence_alert_hours', 6));

                return $lastIntake !== null && Carbon::parse($lastIntake)->diffInHours(now()) > $silenceHours;
            }))
            ->map(fn (Tenant $t) => ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug])
            ->values());

        return response()->json(['data' => [
            // TENANCY: jobs/failed_jobs are shared infrastructure tables, not
            // tenant-owned — one shared queue on this host by design (the
            // same primitive DiagnosticsController reads per-tenant).
            'queue_depth' => DB::table('jobs')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'cron_last_tick' => Cache::get('cron:last_tick'),
            'ai_error_rate_by_provider' => $errorRates,
            'vollna_silent_tenants' => $silentTenants,
        ]]);
    }
}
