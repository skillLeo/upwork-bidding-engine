<?php

namespace App\Services\Members;

use App\Authorization\TenantRole;
use App\Enums\AuthEventType;
use App\Mail\InvitationMail;
use App\Models\AuthEvent;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The invitation lifecycle: create, resend, accept.
 *
 * Only the token HASH is ever stored. The raw 64-char token exists once, in
 * the email link, and is unrecoverable from the database — a leaked backup
 * cannot be turned into a working invite.
 */
class InvitationService
{
    private const TTL_HOURS = 72;

    /**
     * Create an invitation for the CURRENT tenant and email the accept link.
     * Returns the invitation (with the raw token attached transiently, for
     * tests — it is never persisted).
     */
    public function invite(Tenant $tenant, string $email, TenantRole $role, User $invitedBy): Invitation
    {
        [$raw, $hash] = $this->makeToken();

        $invitation = Tenancy::runAs($tenant, fn () => Invitation::create([
            'email' => Str::lower(trim($email)),
            'role' => $role->value,
            'token_hash' => $hash,
            'invited_by' => $invitedBy->id,
            'expires_at' => now()->addHours(self::TTL_HOURS),
        ]));

        $this->sendMail($tenant, $invitation, $raw, $invitedBy);

        // In-memory only (plain property, never persisted).
        $invitation->rawToken = $raw;

        return $invitation;
    }

    /**
     * A NEW token, the old hash invalidated. Resending must not leave the
     * previous link usable.
     */
    public function resend(Tenant $tenant, Invitation $invitation, User $actor): Invitation
    {
        [$raw, $hash] = $this->makeToken();

        Tenancy::runAs($tenant, fn () => $invitation->update([
            'token_hash' => $hash,
            'expires_at' => now()->addHours(self::TTL_HOURS),
        ]));

        $this->sendMail($tenant, $invitation, $raw, $actor);
        $invitation->rawToken = $raw;

        return $invitation;
    }

    /**
     * Look up a live invitation by its raw token, across all tenants (the
     * acceptor is unauthenticated and has no tenant bound). Returns null for
     * an unknown, expired, or already-accepted token — a single-use guard.
     */
    public function findPending(string $rawToken): ?Invitation
    {
        $hash = hash('sha256', $rawToken);

        // TENANCY: an unauthenticated acceptor has no tenant bound, so the
        // token (a 64-char secret, matched by hash) must be looked up across
        // all tenants — the hash IS the authorization here.
        return Tenancy::asPlatform(fn () => Invitation::query()
            ->where('token_hash', $hash)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first());
    }

    /**
     * Accept: create the user if new (or attach an existing one), assign the
     * role within the tenant, stamp accepted_at. Single-use — the token is
     * spent the moment accepted_at is set, and findPending() will never
     * return it again.
     *
     * @param  array{name?: string, password?: string}  $newUserData
     */
    public function accept(Invitation $invitation, array $newUserData = []): User
    {
        // TENANCY: the tenants table is never tenant-scoped.
        $tenant = Tenancy::asPlatform(fn () => Tenant::find($invitation->tenant_id));

        // An existing account with this email joins the workspace; otherwise
        // a new account is created from the supplied name/password.
        $user = User::where('email', $invitation->email)->first();

        if ($user === null) {
            $user = User::create([
                'name' => $newUserData['name'] ?? Str::before($invitation->email, '@'),
                'email' => $invitation->email,
                'password' => $newUserData['password'] ?? Str::random(32),
                'role' => $invitation->role === TenantRole::Admin->value ? 'admin' : 'bidder',
            ]);
        }

        $tenant->users()->syncWithoutDetaching([$user->id => ['joined_at' => now()]]);

        Tenancy::runAs($tenant, function () use ($user, $invitation) {
            $user->syncRoles([$invitation->role]);
        });

        Tenancy::runAs($tenant, fn () => $invitation->update(['accepted_at' => now()]));

        AuthEvent::record(AuthEventType::InviteAccepted, user: $user);

        return $user;
    }

    /**
     * @return array{0: string, 1: string} [rawToken, sha256Hash]
     */
    private function makeToken(): array
    {
        $raw = Str::random(64);

        return [$raw, hash('sha256', $raw)];
    }

    private function sendMail(Tenant $tenant, Invitation $invitation, string $rawToken, User $invitedBy): void
    {
        $url = rtrim((string) config('app.url'), '/').'/accept-invite?token='.$rawToken;

        Mail::to($invitation->email)->send(new InvitationMail(
            workspaceName: $tenant->name,
            invitedByName: $invitedBy->name,
            roleLabel: TenantRole::from($invitation->role)->label(),
            acceptUrl: $url,
            expiresAt: $invitation->expires_at->format('j M Y, H:i').' UTC',
        ));
    }
}
