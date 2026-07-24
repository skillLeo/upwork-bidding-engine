<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin \App\Models\User
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
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
            'platform_role' => $this->platform_role,
            'avatar_url' => $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null,
            'two_factor_enabled' => (bool) $this->two_factor_enabled,
        ];
    }
}
