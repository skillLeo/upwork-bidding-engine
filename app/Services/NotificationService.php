<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Lead;

/**
 * Creates rows for the in-app notification centre (the bell). Best-effort and
 * additive: a notification is a nicety on top of the real pipeline, so callers
 * never depend on it and it never throws into a critical path.
 */
class NotificationService
{
    /**
     * @param array{level?: string, body?: ?string, url?: ?string, lead_id?: ?int} $opts
     */
    public function push(string $type, string $title, array $opts = []): AppNotification
    {
        $notification = AppNotification::create([
            'type' => $type,
            'level' => $opts['level'] ?? 'info',
            'title' => $title,
            'body' => $opts['body'] ?? null,
            'url' => $opts['url'] ?? null,
            'lead_id' => $opts['lead_id'] ?? null,
        ]);

        // Fire a real Web Push too, so it lands on a locked / closed-browser
        // phone - not just the in-app bell. Best-effort; a push failure never
        // affects the notification row or the caller.
        try {
            app(WebPushService::class)->send($title, $opts['body'] ?? null, $opts['url'] ?? null);
        } catch (\Throwable $e) {
            report($e);
        }

        return $notification;
    }

    /**
     * A fresh, bid-worthy lead just landed. Deduped to one per lead so a
     * rescore can't double-notify.
     */
    public function leadReady(Lead $lead): ?AppNotification
    {
        if (AppNotification::query()->where('lead_id', $lead->id)->where('type', 'lead')->exists()) {
            return null;
        }

        $score = (int) ($lead->score ?? 0);

        return $this->push('lead', "New {$score}/10 lead", [
            'level' => $score >= 9 ? 'success' : 'info',
            'body' => (string) $lead->title,
            'url' => "/leads/{$lead->id}",
            'lead_id' => $lead->id,
        ]);
    }

    public function reminder(Lead $lead, string $body): AppNotification
    {
        return $this->push('reminder', 'Follow-up reminder', [
            'level' => 'warning',
            'body' => $body,
            'url' => "/leads/{$lead->id}",
            'lead_id' => $lead->id,
        ]);
    }
}
