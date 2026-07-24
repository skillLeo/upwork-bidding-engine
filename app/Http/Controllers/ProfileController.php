<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\AuthEventType;
use App\Http\Resources\UserResource;
use App\Models\ActivityLog;
use App\Models\AuthEvent;
use App\Models\NotificationPreference;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => ['sometimes', 'nullable', 'string'],
        ]);

        // A leaked/stolen token shouldn't be enough to silently redirect
        // future logins to an attacker's own email - changing it requires
        // re-proving the password, same as changing the password itself
        // does. Name-only edits don't need this.
        $changingEmail = $validated['email'] !== $user->email;

        if ($changingEmail && ! Hash::check((string) ($validated['current_password'] ?? ''), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Enter your current password to change your email.'],
            ]);
        }

        $user->update(['name' => $validated['name'], 'email' => $validated['email']]);

        ActivityLog::record(ActivityType::SettingUpdated, subject: $user, userId: $user->id, meta: [
            'action' => 'profile_updated',
            'email_changed' => $changingEmail,
        ]);

        return response()->json(['data' => new UserResource($user)]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->update(['password' => $validated['password']]);

        // Changing the password should actually sign out other
        // sessions/devices - keep only the token used for this request.
        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))->delete();

        ActivityLog::record(ActivityType::SettingUpdated, subject: $user, userId: $user->id, meta: [
            'action' => 'password_changed',
        ]);

        AuthEvent::record(AuthEventType::PasswordChanged, user: $user, request: $request);

        return response()->json(['data' => ['message' => 'Password updated.']]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            // Not Laravel's generic `image` rule - that permits SVG, which
            // can carry an inline <script> and execute if its URL is ever
            // opened directly. An explicit raster-only mimes whitelist
            // closes that off; nothing here needs vector graphics.
            'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar_path' => $path]);

        ActivityLog::record(ActivityType::SettingUpdated, subject: $user, userId: $user->id, meta: [
            'action' => 'avatar_updated',
        ]);

        return response()->json(['data' => new UserResource($user)]);
    }

    public function toggleTwoFactor(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $user->update(['two_factor_enabled' => $validated['enabled']]);

        ActivityLog::record(ActivityType::SettingUpdated, subject: $user, userId: $user->id, meta: [
            'action' => 'two_factor_toggled',
            'enabled' => $validated['enabled'],
        ]);

        AuthEvent::record(
            $validated['enabled'] ? AuthEventType::TwoFactorEnabled : AuthEventType::TwoFactorDisabled,
            user: $user,
            request: $request,
        );

        return response()->json(['data' => new UserResource($user)]);
    }

    // -------------------------------------------------- notification prefs

    public function notificationPreferences(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentPreferences($this->findOrDefaultPreferences($request->user()))]);
    }

    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email_on_new_lead' => ['required', 'boolean'],
            'email_on_reminder' => ['required', 'boolean'],
            'push_on_new_lead' => ['required', 'boolean'],
            'push_on_reminder' => ['required', 'boolean'],
            'quiet_hours_start' => ['nullable', 'integer', 'min:0', 'max:23'],
            'quiet_hours_end' => ['nullable', 'integer', 'min:0', 'max:23'],
        ]);

        $prefs = NotificationPreference::updateOrCreate(
            ['user_id' => $request->user()->id],
            $validated,
        );

        return response()->json(['data' => $this->presentPreferences($prefs)]);
    }

    private function findOrDefaultPreferences(User $user): NotificationPreference
    {
        return NotificationPreference::where('user_id', $user->id)->first()
            ?? NotificationPreference::defaultsFor($user->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPreferences(NotificationPreference $prefs): array
    {
        return [
            'email_on_new_lead' => $prefs->email_on_new_lead,
            'email_on_reminder' => $prefs->email_on_reminder,
            'push_on_new_lead' => $prefs->push_on_new_lead,
            'push_on_reminder' => $prefs->push_on_reminder,
            'quiet_hours_start' => $prefs->quiet_hours_start,
            'quiet_hours_end' => $prefs->quiet_hours_end,
        ];
    }

    // -------------------------------------------------------- my workspaces

    /**
     * Every workspace this account belongs to, with the role held in each
     * and a best-effort link to switch (tenants are resolved by subdomain —
     * see config/tenancy.php — so "switching" is a navigation, not a
     * server-side session flip).
     */
    public function workspaces(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = Tenancy::current();

        $tenants = $user->tenants()->orderBy('name')->get()->map(function (Tenant $tenant) use ($user, $current) {
            $role = Tenancy::runAs($tenant, fn () => $user->getRoleNames()->first());

            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'role' => $role,
                'is_owner' => $tenant->owner_user_id === $user->id,
                'is_current' => $current !== null && $current->id === $tenant->id,
                'switch_url' => $this->switchUrl($tenant),
            ];
        });

        return response()->json(['data' => ['workspaces' => $tenants]]);
    }

    /**
     * Leave a workspace. The owner cannot leave their own workspace — they
     * would leave it ownerless — they must transfer ownership first (Settings
     * > Workspace, owner-only), same lock as the delete-workspace action.
     */
    public function leaveWorkspace(Request $request, Tenant $tenant): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->tenants()->where('tenants.id', $tenant->id)->exists(), 404);

        if ($tenant->owner_user_id === $user->id) {
            throw ValidationException::withMessages([
                'workspace' => ['Transfer ownership before leaving this workspace.'],
            ]);
        }

        Tenancy::runAs($tenant, function () use ($user, $tenant) {
            $user->syncRoles([]);
            $user->tenants()->detach($tenant->id);
        });

        \Laravel\Sanctum\PersonalAccessToken::where('tenant_id', $tenant->id)
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->delete();

        ActivityLog::record(ActivityType::SettingUpdated, subject: $tenant, userId: $user->id, meta: [
            'action' => 'workspace_left',
        ]);

        return response()->json(['data' => ['message' => "You left {$tenant->name}."]]);
    }

    // --------------------------------------------------- connected accounts

    public function socialAccounts(Request $request): JsonResponse
    {
        $accounts = SocialAccount::where('user_id', $request->user()->id)->get()
            ->map(fn (SocialAccount $a) => [
                'provider' => $a->provider,
                'linked_at' => $a->linked_at?->toIso8601String(),
            ]);

        return response()->json(['data' => ['accounts' => $accounts]]);
    }

    /**
     * Refused when the account has no password (P6) — unlinking Google from
     * a Google-only account would be a permanent lockout. See
     * User::hasPassword().
     */
    public function unlinkSocialAccount(Request $request, string $provider): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPassword()) {
            throw ValidationException::withMessages([
                'provider' => ['Set a password before unlinking Google — this account has no other way to sign in.'],
            ]);
        }

        $deleted = SocialAccount::where('user_id', $user->id)->where('provider', $provider)->delete();

        abort_if($deleted === 0, 404, 'No linked account for that provider.');

        AuthEvent::record(\App\Enums\AuthEventType::SocialAccountUnlinked, user: $user, request: $request);

        return response()->json(['data' => ['message' => ucfirst($provider).' account unlinked.']]);
    }

    private function switchUrl(Tenant $tenant): ?string
    {
        $appUrl = parse_url((string) config('app.url'));
        $host = $appUrl['host'] ?? null;

        if (! is_string($host)) {
            return null;
        }

        $labels = explode('.', $host);

        // Need at least "something.example.tld" left after swapping the
        // first label for the target slug, or the link would be meaningless
        // (e.g. bare "localhost").
        if (count($labels) < 2) {
            return null;
        }

        $labels[0] = $tenant->slug;
        $scheme = $appUrl['scheme'] ?? 'https';

        return $scheme.'://'.implode('.', $labels);
    }
}
