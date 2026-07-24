<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Enums\MessageDirection;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['client_id', 'direction', 'text', 'drafted_reply', 'needs_hassam', 'sent_at'])]
class Message extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'needs_hassam' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
