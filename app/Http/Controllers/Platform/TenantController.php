<?php

namespace App\Http\Controllers\Platform;

use App\Authorization\PlatformRole;
use App\Authorization\RoleProvisioner;
use App\Authorization\TenantRole;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AiCall;
use App\Models\AuthEvent;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\AiQuotaService;
use App\Services\Members\InvitationService;
use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use App\Tenancy\TenantTeamResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
            'specialization' => $t->specialization,
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

    /**
     * Create a workspace and invite its owner (P8).
     *
     * This is the CLOSED-SIGNUP path: the platform owner opens a workspace
     * for a customer and mails them an owner invitation. It coexists with the
     * signup_mode setting — self-serve registration is a separate door and
     * this does not replace it.
     *
     * The tenant is created with NO owner_user_id. Accepting the invitation
     * is what stamps it (see InvitationService::accept), which is why an
     * owner invitation can only ever be minted here: there is no other place
     * in the app that creates an ownerless tenant needing one.
     */
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        abort_unless(
            $actor->platformRole() === PlatformRole::Owner,
            403,
            'Only the platform owner can create a workspace.',
        );

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:63', 'alpha_dash', 'unique:tenants,slug'],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:80'],
            'owner_email' => ['required', 'email', 'max:255'],
            // Set a password and the account is created and ready NOW, with
            // no email round-trip. Leave it blank and an invitation is sent
            // instead. Both doors exist because email is the part of this
            // that can fail silently — a platform owner opening an account
            // for a customer should not be blocked by an SMTP problem.
            'owner_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'owner_password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:255'],
        ]);

        // TENANCY: creating a tenant is inherently a platform act — there is
        // no tenant to be scoped to yet, and the tenants table is never
        // tenant-scoped in the first place.
        $tenant = Tenancy::asPlatform(fn () => Tenant::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'plan' => 'free',
            'status' => Tenant::STATUS_TRIALING,
        ]));

        if (array_key_exists('specialization', $validated)) {
            $tenant->update(['specialization' => $validated['specialization']]);
        }

        app(RoleProvisioner::class)->provision($tenant);

        $email = strtolower(trim($validated['owner_email']));
        $password = trim((string) ($validated['owner_password'] ?? ''));

        if ($password !== '') {
            $message = $this->createOwnerDirectly($tenant, $email, $validated['owner_name'] ?? null, $password);
        } else {
            app(InvitationService::class)->invite($tenant, $email, TenantRole::Owner, $actor);
            $message = "Workspace created. An owner invitation is on its way to {$email}.";
        }

        ActivityLog::record('workspace_created', meta: [
            'tenant_id' => $tenant->id,
            'slug' => $tenant->slug,
            'owner_email' => $email,
            'owner_created_directly' => $password !== '',
        ], userId: $actor->id);

        return response()->json(['data' => [
            'message' => $message,
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'specialization' => $tenant->specialization,
                'plan' => $tenant->plan,
                'status' => $tenant->status,
            ],
        ]], 201);
    }

    /**
     * Create the workspace's owner account outright — no invitation, no
     * email, usable immediately.
     *
     * An EXISTING account with this email is attached rather than
     * duplicated, and its password is left alone: the platform owner is
     * opening a workspace for someone, which is not authority to reset a
     * password on an account that person already uses elsewhere.
     */
    private function createOwnerDirectly(Tenant $tenant, string $email, ?string $name, string $password): string
    {
        $existing = User::where('email', $email)->first();

        $user = $existing ?? User::create([
            'name' => $name ?: Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::make($password),
            // Legacy display column only; authorization reads the Spatie role.
            'role' => UserRole::Admin->value,
        ]);

        $tenant->users()->syncWithoutDetaching([$user->id => ['joined_at' => now()]]);
        $tenant->update(['owner_user_id' => $user->id]);

        // Pinned: Spatie resolves the team through TenantTeamResolver, which
        // prefers a leftover override — and provision() ran moments ago.
        TenantTeamResolver::pinnedTo(
            $tenant->id,
            fn () => $user->syncRoles([TenantRole::Owner->value]),
        );

        return $existing !== null
            ? "Workspace created. {$email} already had an account, so it was made the owner — their existing password still applies."
            : "Workspace created. {$email} can sign in now with the password you set.";
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
                'specialization' => $t->specialization,
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
