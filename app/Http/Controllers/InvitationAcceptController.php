<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\TokenIssuer;
use App\Services\Members\InvitationService;
use App\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public side of invitations — reached from the email link with no
 * session, so unauthenticated by necessity. The raw token IS the credential;
 * it is looked up by hash, and is single-use (accepted_at spends it).
 */
class InvitationAcceptController extends Controller
{
    public function __construct(protected InvitationService $invitations) {}

    /**
     * Show what the accept page needs to render: the workspace, who invited,
     * the role, and whether the invited email already has an account (so the
     * page shows "Join" rather than a name/password form).
     */
    public function show(Request $request): JsonResponse
    {
        $invitation = $this->invitations->findPending((string) $request->query('token'));

        if ($invitation === null) {
            return response()->json(['message' => 'This invitation is invalid or has expired.'], 404);
        }

        // TENANCY: the tenants table is never tenant-scoped.
        $tenant = Tenancy::asPlatform(fn () => Tenant::find($invitation->tenant_id));
        $existingUser = User::where('email', $invitation->email)->exists();

        return response()->json(['data' => [
            'workspace' => $tenant?->name,
            'email' => $invitation->email,
            'role' => $invitation->role,
            // TENANCY: reading the inviter (a user) for display; not tenant data.
            'invited_by' => Tenancy::asPlatform(fn () => $invitation->inviter?->name),
            'needs_account' => ! $existingUser,
        ]]);
    }

    /**
     * Accept the invitation. Issues a real session token on success, so the
     * new member lands signed in rather than at a login screen.
     */
    public function accept(Request $request, TokenIssuer $tokens): JsonResponse
    {
        $invitation = $this->invitations->findPending((string) $request->input('token'));

        if ($invitation === null) {
            return response()->json(['message' => 'This invitation is invalid or has expired.'], 404);
        }

        $needsAccount = ! User::where('email', $invitation->email)->exists();

        $validated = $request->validate($needsAccount ? [
            'token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ] : [
            'token' => ['required', 'string'],
        ]);

        $user = $this->invitations->accept($invitation, [
            'name' => $validated['name'] ?? null,
            'password' => $validated['password'] ?? null,
        ]);

        // Bind the tenant the invite belonged to before issuing the token, so
        // the token records the right workspace.
        // TENANCY: the tenants table is never tenant-scoped.
        $tenant = Tenancy::asPlatform(fn () => Tenant::find($invitation->tenant_id));

        $issued = Tenancy::runAs($tenant, fn () => $tokens->issue($user, $request));

        return response()->json(['data' => [
            'token' => $issued['token'],
            'message' => "Welcome to {$tenant->name}.",
        ]]);
    }
}
