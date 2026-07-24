<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One user's read state for one notification.
 *
 * Not tenant-scoped by the global trait: it is keyed by (notification, user)
 * and the notification itself is already tenant-scoped, so a read row can
 * only ever exist for a notification the user could see. Its own read/write
 * paths always join through app_notifications, which carries the scope.
 */
class AppNotificationRead extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['app_notification_id', 'user_id', 'read_at'];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }
}
