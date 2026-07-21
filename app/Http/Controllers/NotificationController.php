<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    /**
     * The bell's feed: the newest notifications plus the unread count. Kept
     * small (30) and cheap so the frontend can poll it every few seconds.
     */
    public function index(): JsonResponse
    {
        $items = AppNotification::query()->latest('id')->limit(30)->get();

        return response()->json([
            'data' => $items->map(fn (AppNotification $n) => $this->present($n))->all(),
            'meta' => ['unread_count' => AppNotification::query()->whereNull('read_at')->count()],
        ]);
    }

    public function markRead(AppNotification $notification): JsonResponse
    {
        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['data' => $this->present($notification->fresh())]);
    }

    public function markAllRead(): JsonResponse
    {
        AppNotification::query()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => ['unread_count' => 0]]);
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
            'read' => $n->read_at !== null,
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
