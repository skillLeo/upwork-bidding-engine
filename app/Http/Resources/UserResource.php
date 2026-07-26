<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            // The tenant role for the CURRENTLY bound workspace (Spatie), and
            // the flat permission list. The frontend uses `permissions` to
            // HIDE actions the user can't perform — hide, not disable, so a
            // button that can never work is never shown.
            'tenant_role' => $this->getRoleNames()->first(),
            // EFFECTIVE permissions: role grants + per-user direct grants,
            // MINUS per-user denies (the Gate::before hook enforces the same
            // subtraction server-side). The owner is exempt from denies.
            'permissions' => $this->effectivePermissions(),
            'platform_role' => $this->platform_role,
            // The workspace this session is bound to. `plan` is what gates
            // internal-only surfaces (the AI Engine tab, the OpenClaw
            // WhatsApp bridge) in the UI — the server gates them too, so this
            // only decides what is worth rendering.
            'tenant' => $this->tenantContext(),
            'avatar_url' => $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null,
            'two_factor_enabled' => (bool) $this->two_factor_enabled,
            // P6: authenticator-app TOTP status, separate from the email-OTP
            // flag above — a user may hold either, both, or neither.
            'totp_enabled' => $this->hasTotpEnabled(),
            'has_password' => $this->hasPassword(),
        ];
    }

    /**
     * @return array{id: int, name: string, slug: string, plan: string, specialization: ?string}|null
     */
    private function tenantContext(): ?array
    {
        $tenant = Tenancy::current();

        if ($tenant === null) {
            return null;
        }

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'plan' => $tenant->plan,
            'specialization' => $tenant->specialization,
        ];
    }
}
