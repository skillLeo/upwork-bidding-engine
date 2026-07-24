<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AiCall;
use App\Models\AuthEvent;
use App\Models\Lead;
use App\Models\Tenant;
use App\Services\Ai\AiQuotaService;
use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Tenants list and Tenant detail (P5 platform console).
 *
 * Tenant detail is content-safe BY CONSTRUCTION: no query anywhere in this
 * class selects a secret setting value, Lead::proposal_text, or a Message
 * body — not "hidden in the response shape" but never fetched from the
 * database in the first place, which is what the spec requires ("enforce in
 * the query, not the template"). Any platform role can see this controller's
 * output; the wall between "may see metadata" and "may see content" is
 * enforced by never having content-bearing columns to leak.
 */
class TenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $plan = $request->query('plan');
        $status = $request->query('status');

        // TENANCY: the platform console reads across every tenant by design —
        // this controller is the audited cross-tenant exception.
        $tenants = Tenancy::asPlatform(fn () => Tenant::query()
            ->when($search !== '', fn ($q) => $q->where(
                fn ($q2) => $q2->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")
            ))
            ->when($plan, fn ($q) => $q->where('plan', $plan))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('name')
            ->get());

        $ids = $tenants->pluck('id');
        $monthStart = now()->startOfMonth();

        // TENANCY: aggregate counts across tenants, for the list's columns.
        [$members, $leads, $spend, $lastActivity] = Tenancy::asPlatform(function () use ($ids, $monthStart) {
            return [
                DB::table('tenant_users')->whereIn('tenant_id', $ids)
                    ->selectRaw('tenant_id, count(*) c')->groupBy('tenant_id')->pluck('c', 'tenant_id'),
                Lead::query()->whereIn('tenant_id', $ids)->where('created_at', '>=', $monthStart)
                    ->selectRaw('tenant_id, count(*) c')->groupBy('tenant_id')->pluck('c', 'tenant_id'),
                AiCall::query()->whereIn('tenant_id', $ids)->where('created_at', '>=', $monthStart)
                    ->selectRaw('tenant_id, sum(cost_usd) c')->groupBy('tenant_id')->pluck('c', 'tenant_id'),
                ActivityLog::query()->whereIn('tenant_id', $ids)
                    ->selectRaw('tenant_id, max(created_at) c')->groupBy('tenant_id')->pluck('c', 'tenant_id'),
            ];
        });

        $data = $tenants->map(fn (Tenant $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'slug' => $t->slug,
            'plan' => $t->plan,
            'status' => $t->status,
            'members' => (int) ($members[$t->id] ?? 0),
            'leads_this_month' => (int) ($leads[$t->id] ?? 0),
            'ai_spend_this_month' => round((float) ($spend[$t->id] ?? 0), 2),
            'last_activity_at' => $lastActivity[$t->id] ?? null,
            'health' => $this->healthOf($t)['status'],
        ])->values();

        return response()->json(['data' => ['tenants' => $data]]);
    }

    public function show(int $tenant): JsonResponse
    {
        // TENANCY: withTrashed — support still needs to see a deleted
        // workspace existed. Cross-tenant resolution by id is the platform
        // console's whole reason to exist.
        $t = Tenancy::asPlatform(fn () => Tenant::withTrashed()->findOrFail($tenant));

        $members = Tenancy::runAs($t, fn () => $t->users()->get()->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->getRoleNames()->first(),
            'is_owner' => $u->id === $t->owner_user_id,
        ]))->values();

        $sixMonthsAgo = now()->subMonths(5)->startOfMonth();

        // TENANCY: usage-over-time for ONE tenant, explicitly filtered by id
        // — never a bare unscoped read.
        $usage = Tenancy::asPlatform(fn () => AiCall::query()
            ->where('tenant_id', $t->id)
            ->where('created_at', '>=', $sixMonthsAgo)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') ym, count(*) calls, sum(cost_usd) cost, sum(input_tokens + output_tokens) tokens")
            ->groupBy('ym')->orderBy('ym')->get())
            ->map(fn ($row) => [
                'month' => $row->ym,
                'calls' => (int) $row->calls,
                'cost_usd' => round((float) $row->cost, 2),
                'tokens' => (int) $row->tokens,
            ]);

        // TENANCY: count only — never the lead rows themselves
        // (title/proposal_text stay out of every query in this controller).
        $leadsThisMonth = Tenancy::asPlatform(fn () => Lead::query()
            ->where('tenant_id', $t->id)->where('created_at', '>=', now()->startOfMonth())->count());

        $recentEvents = AuthEvent::where('tenant_id', $t->id)->latest('id')->limit(20)->get()
            ->map(fn (AuthEvent $e) => [
                'event' => $e->event->value,
                'label' => $e->event->label(),
                'user_id' => $e->user_id,
                'ip' => $e->ip,
                'approx_location' => $e->approx_location,
                'created_at' => $e->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => [
            'tenant' => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'plan' => $t->plan,
                'status' => $t->status,
                'created_at' => $t->created_at?->toIso8601String(),
                'deleted_at' => $t->deleted_at?->toIso8601String(),
            ],
            'members' => $members,
            'usage_over_time' => $usage->values(),
            'health' => $this->healthOf($t),
            'leads_this_month' => $leadsThisMonth,
            'recent_auth_events' => $recentEvents,
        ]]);
    }

    /**
     * @return array{billing_blocked: bool, vollna_silent: bool, ai_over_cap: bool, status: string}
     */
    private function healthOf(Tenant $t): array
    {
        return Tenancy::runAs($t, function () use ($t) {
            $settings = app(SettingsService::class);
            $quota = app(AiQuotaService::class);

            $lastIntake = $settings->get('vollna_last_intake_at');
            $silenceHours = max(1, (int) $settings->get('vollna_silence_alert_hours', 6));
            $vollnaSilent = $lastIntake !== null
                && Carbon::parse($lastIntake)->diffInHours(now()) > $silenceHours;

            $overCap = $quota->isOverCap();

            return [
                'billing_blocked' => $t->isBillingBlocked(),
                'vollna_silent' => $vollnaSilent,
                'ai_over_cap' => $overCap,
                'status' => $t->isBillingBlocked() ? 'billing_blocked' : (($vollnaSilent || $overCap) ? 'attention' : 'ok'),
            ];
        });
    }
}
