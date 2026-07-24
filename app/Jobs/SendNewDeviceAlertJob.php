<?php

namespace App\Jobs;

use App\Mail\NewDeviceAlertMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Emails a user when their account is signed in to from a device that has
 * not been seen in 90 days.
 *
 * Deliberately NOT using RunsForTenant, unlike every other job here. This is
 * about a USER, not a workspace: a user can belong to several tenants, the
 * alert is the same regardless of which one they signed in to, and it must
 * still send if that tenant is later suspended. It touches no tenant-scoped
 * model, so the global scope is never engaged.
 */
class SendNewDeviceAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public int $userId,
        public string $device,
        public ?string $location,
        public ?string $ip,
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        // A signed URL, so the link works from an email client with no
        // session and still cannot be forged or replayed after it expires.
        // 7 days: long enough to survive a weekend and an unread inbox,
        // short enough that a leaked mailbox months later is not a live
        // account-takeover tool.
        $revokeUrl = URL::temporarySignedRoute(
            'auth.revoke-all',
            now()->addDays(7),
            ['user' => $user->id],
        );

        Mail::to($user->email)->send(new NewDeviceAlertMail(
            userName: $user->name,
            device: $this->device,
            location: $this->location,
            ip: $this->ip,
            signedInAt: now()->format('j M Y, H:i').' UTC',
            revokeUrl: $revokeUrl,
        ));
    }
}
