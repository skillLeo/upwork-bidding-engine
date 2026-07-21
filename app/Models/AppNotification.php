<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row in the in-app notification centre (the bell). See the
 * create_app_notifications_table migration for why it's account-wide.
 */
#[Fillable(['type', 'level', 'title', 'body', 'url', 'lead_id', 'read_at'])]
class AppNotification extends Model
{
    public const UPDATED_AT = null; // created_at only; a notification is immutable bar read_at.

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }
}
