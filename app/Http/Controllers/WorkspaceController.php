<?php

namespace App\Http\Controllers;

use App\Authorization\TenantRole;
use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Tenant;
use App\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Workspace tab (P5) — name/slug, plan/status (read-only, no billing
 * here), member count, transfer ownership, export, delete. Everything is
 * scoped to Tenancy::current(); there is no tenant id in any request here,
 * on purpose — a caller cannot manage a workspace they aren't in.
 *
 * Transfer/delete are hardcoded to the tenant's owner_user_id rather than
 * gated by workspace.manage — the same "not even delegable" lock P4 already
 * uses for the Owner role itself. Handing an admin the ability to give away
 * or destroy the workspace via a permission an owner could absent-mindedly
 * grant is a different risk profile than "can rename the workspace."
 */
class WorkspaceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present(Tenancy::current())]);
    }

    public function update(Request $request): JsonResponse
    {
        $tenant = Tenancy::current();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:63', 'alpha_dash',
                Rule::unique('tenants', 'slug')->ignore($tenant->id),
            ],
            // A display label only — nothing in scoring or proposal writing
            // reads it (see Tenant::specializationLabel).
            'specialization' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        $tenant->update($validated);

        ActivityLog::record(ActivityType::SettingUpdated, subject: $tenant, userId: $request->user()->id, meta: [
            'action' => 'workspace_updated',
        ]);

        return response()->json(['data' => $this->present($tenant->fresh())]);
    }

    public function transferOwnership(Request $request): JsonResponse
    {
        $tenant = Tenancy::current();
        $user = $request->user();

        abort_unless($tenant->owner_user_id === $user->id, 403, 'Only the current owner can transfer ownership.');

        $validated = $request->validate(['new_owner_user_id' => ['required', 'integer']]);

        $newOwner = $tenant->users()->whereKey($validated['new_owner_user_id'])->first();

        if ($newOwner === null) {
            throw ValidationException::withMessages([
                'new_owner_user_id' => ['That person is not a member of this workspace.'],
            ]);
        }

        if ((int) $newOwner->id === (int) $user->id) {
            throw ValidationException::withMessages([
                'new_owner_user_id' => ['You already own this workspace.'],
            ]);
        }

        DB::transaction(function () use ($tenant, $user, $newOwner) {
            $tenant->update(['owner_user_id' => $newOwner->id]);
            // Spatie role assignments are per-team (tenant_id), resolved from
            // the SAME bound tenant this request is already running as.
            $newOwner->syncRoles([TenantRole::Owner->value]);
            // The outgoing owner lands on BIDDER, not viewer: they keep
            // working the pipeline, which is what someone handing over
            // day-to-day ownership almost always still wants to do. (Before
            // P8 this demoted to 'admin', a role that no longer exists.) The
            // new owner can move them to viewer in one click.
            $user->syncRoles([TenantRole::Bidder->value]);
        });

        ActivityLog::record(ActivityType::SettingUpdated, subject: $tenant, userId: $user->id, meta: [
            'action' => 'ownership_transferred',
            'from_user_id' => $user->id,
            'to_user_id' => $newOwner->id,
        ]);

        return response()->json(['data' => array_merge(
            ['message' => "Ownership transferred to {$newOwner->name}."],
            $this->present($tenant->fresh()),
        )]);
    }

    /**
     * A JSON download of the workspace's own data. Deliberately NOT the
     * platform console's content-safe subset — this is the tenant exporting
     * ITS OWN data, so proposal text and client notes are included; only
     * settings secrets (API keys, tokens) stay out, since a downloaded file
     * is a worse place for those than the Settings screen they already live
     * behind their own permission.
     */
    public function exportData(Request $request): StreamedResponse
    {
        $tenant = Tenancy::current();

        $leads = Lead::query()->orderBy('id')->get()->map(fn (Lead $l) => [
            'id' => $l->id,
            'title' => $l->title,
            'url' => $l->url,
            'status' => $l->status?->value,
            'outcome' => $l->outcome?->value,
            'score' => $l->score,
            'budget' => $l->budget,
            'client_country' => $l->client_country,
            'proposal_text' => $l->proposal_text,
            'posted_at' => $l->posted_at?->toIso8601String(),
            'submitted_at' => $l->submitted_at?->toIso8601String(),
            'created_at' => $l->created_at?->toIso8601String(),
        ]);

        $clients = Client::query()->orderBy('id')->get()->map(fn (Client $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'lead_id' => $c->lead_id,
            'stage' => $c->stage?->value,
            'agreed_scope' => $c->agreed_scope,
            'budget_discussed' => $c->budget_discussed,
            'notes' => $c->notes,
        ]);

        $members = $tenant->users()->get()->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'joined_at' => optional($u->pivot->joined_at)->toIso8601String() ?? null,
        ]);

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'workspace' => ['name' => $tenant->name, 'slug' => $tenant->slug],
            'leads' => $leads,
            'clients' => $clients,
            'members' => $members,
        ];

        ActivityLog::record(ActivityType::SettingUpdated, subject: $tenant, userId: $request->user()->id, meta: [
            'action' => 'workspace_data_exported',
        ]);

        $filename = Str::slug($tenant->slug).'-export-'.now()->format('Y-m-d').'.json';

        return response()->streamDownload(function () use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, $filename, ['Content-Type' => 'application/json']);
    }

    /**
     * Soft-delete — see Tenant::class docblock. Type-the-slug is the
     * confirmation; there is no undo route yet (restoring is a platform
     * console / support action, not self-service), so the copy in the UI
     * says exactly that.
     */
    public function destroy(Request $request): JsonResponse
    {
        $tenant = Tenancy::current();
        $user = $request->user();

        abort_unless($tenant->owner_user_id === $user->id, 403, 'Only the owner can delete the workspace.');

        $validated = $request->validate(['confirm_slug' => ['required', 'string']]);

        if ($validated['confirm_slug'] !== $tenant->slug) {
            throw ValidationException::withMessages([
                'confirm_slug' => ['Type the workspace slug exactly to confirm.'],
            ]);
        }

        ActivityLog::record(ActivityType::SettingUpdated, subject: $tenant, userId: $user->id, meta: [
            'action' => 'workspace_deleted',
        ]);

        DB::transaction(function () use ($tenant) {
            // Every live session in this workspace ends immediately — a
            // soft-deleted tenant that still answers API calls on
            // already-issued tokens is not actually "stopped".
            PersonalAccessToken::where('tenant_id', $tenant->id)->delete();
            $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
            $tenant->delete();
        });

        return response()->json(['data' => ['message' => 'Workspace deleted. Every member has been signed out.']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Tenant $tenant): array
    {
        $owner = $tenant->owner;

        return [
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'specialization' => $tenant->specialization,
            'plan' => $tenant->plan,
            'status' => $tenant->status,
            'member_count' => $tenant->users()->count(),
            'owner' => $owner ? ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email] : null,
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }
}
