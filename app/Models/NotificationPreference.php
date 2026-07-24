<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per (tenant, user) — which events email/push this person, and
 * their quiet hours. Read by NotificationService before it fans a bell
 * notification out to email/push, and by WebPushService for subscriptions
 * it can attribute to a user (see push_subscriptions.user_id).
 */
#[Fillable([
    'user_id', 'email_on_new_lead', 'email_on_reminder',
    'push_on_new_lead', 'push_on_reminder', 'quiet_hours_start', 'quiet_hours_end',
])]
class NotificationPreference extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'email_on_new_lead' => 'boolean',
            'email_on_reminder' => 'boolean',
            'push_on_new_lead' => 'boolean',
            'push_on_reminder' => 'boolean',
            'quiet_hours_start' => 'integer',
            'quiet_hours_end' => 'integer',
        ];
    }

    /**
     * Defaults for a user who has never touched this screen — every channel
     * on, no quiet hours. Never persisted until they actually save.
     */
    public static function defaultsFor(int $userId): self
    {
        return new self([
            'user_id' => $userId,
            'email_on_new_lead' => true,
            'email_on_reminder' => true,
            'push_on_new_lead' => true,
            'push_on_reminder' => true,
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ]);
    }

    /**
     * Is "now" (app timezone) inside this user's quiet hours? Handles the
     * overnight wrap (e.g. 22 -> 7) as well as the normal same-day range.
     */
    public function isQuietNow(): bool
    {
        if ($this->quiet_hours_start === null || $this->quiet_hours_end === null) {
            return false;
        }

        $hour = now()->hour;
        $start = $this->quiet_hours_start;
        $end = $this->quiet_hours_end;

        if ($start === $end) {
            return false;
        }

        return $start < $end
            ? ($hour >= $start && $hour < $end)
            : ($hour >= $start || $hour < $end);
    }
}
