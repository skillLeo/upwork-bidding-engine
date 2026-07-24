<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    /**
     * The bell's feed: the newest notifications plus THIS USER'S unread
     * count. Read state used to be one shared read_at on the notification, so
     * one person opening the bell cleared it for everybody. It is now per
     * user (app_notification_reads), joined here so a notification carries a
     * `read` flag specific to the caller.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $items = AppNotification::query()
            ->leftJoin('app_notification_reads', function ($join) use ($userId) {
                $join->on('app_notification_reads.app_notification_id', '=', 'app_notifications.id')
                    ->where('app_notification_reads.user_id', '=', $userId);
            })
            ->orderByDesc('app_notifications.id')
            ->limit(30)
            ->get([
                'app_notifications.*',
                'app_notification_reads.read_at as user_read_at',
            ]);

        return response()->json([
            'data' => $items->map(fn (AppNotification $n) => $this->present($n))->all(),
            'meta' => ['unread_count' => $this->unreadCount($userId)],
        ]);
    }

    public function markRead(Request $request, AppNotification $notification): JsonResponse
    {
        $this->markReadFor($notification->id, $request->user()->id);

        return response()->json(['data' => ['id' => $notification->id, 'read' => true]]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $unreadIds = $this->unreadQuery($userId)->pluck('app_notifications.id');

        foreach ($unreadIds->chunk(500) as $chunk) {
            // TENANCY: app_notification_reads is keyed by (notification,user);
            // $unreadIds already came from the tenant-scoped AppNotification
            // query, so every id here belongs to the caller's workspace.
            DB::table('app_notification_reads')->insertOrIgnore(
                $chunk->map(fn ($id) => [
                    'app_notification_id' => $id,
                    'user_id' => $userId,
                    'read_at' => now(),
                ])->all()
            );
        }

        return response()->json(['data' => ['unread_count' => 0]]);
    }

    private function unreadCount(int $userId): int
    {
        return $this->unreadQuery($userId)->count();
    }

    /**
     * Notifications this user has no read row for. The tenant scope on
     * AppNotification still applies, so this only ever spans the caller's own
     * workspace.
     *
     * @return \Illuminate\Database\Eloquent\Builder<AppNotification>
     */
    private function unreadQuery(int $userId)
    {
        return AppNotification::query()->whereNotExists(function ($q) use ($userId) {
            // TENANCY: the outer AppNotification query carries the tenant
            // scope; this correlated subquery only filters those rows by the
            // user's own read state, spanning no other tenant.
            $q->select(DB::raw(1))
                ->from('app_notification_reads')
                ->whereColumn('app_notification_reads.app_notification_id', 'app_notifications.id')
                ->where('app_notification_reads.user_id', $userId);
        });
    }

    private function markReadFor(int $notificationId, int $userId): void
    {
        // TENANCY: the $notificationId is only ever a route-bound
        // AppNotification the caller could see (tenant-scoped binding), so the
        // read row can only be written for a notification in their workspace.
        DB::table('app_notification_reads')->insertOrIgnore([
            'app_notification_id' => $notificationId,
            'user_id' => $userId,
            'read_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(AppNotification $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'level' => $n->level,
            'title' => $n->title,
            'body' => $n->body,
            'url' => $n->url,
            'lead_id' => $n->lead_id,
            // The joined per-user read timestamp, aliased in index().
            'read' => $n->getAttribute('user_read_at') !== null,
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
