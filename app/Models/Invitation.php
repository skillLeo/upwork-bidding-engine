<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pending workspace invitation. The table ships in this phase; the flow
 * that consumes it (send, accept, resend, revoke) is P4 — nothing writes
 * here yet.
 *
 * Only the token HASH is ever stored. The raw token exists once, in the
 * email, and is never recoverable from the database.
 */
#[Fillable(['email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at'])]
class Invitation extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    protected $hidden = ['token_hash'];

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
