<?php

namespace App\Services;

use App\Mail\LeadNotificationMail;
use App\Models\AppNotification;
use App\Models\Lead;
use App\Models\NotificationPreference;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Mail;

/**
 * Creates rows for the in-app notification centre (the bell). Best-effort and
 * additive: a notification is a nicety on top of the real pipeline, so callers
 * never depend on it and it never throws into a critical path.
 */
class NotificationService
{
    /**
     * Notification "type" -> the NotificationPreference column that gates
     * email for it. Only the two types this app actually produces (see
     * AppNotification's own comment) have a preference at all — anything
     * else stays un-emailed rather than guessing a mapping.
     */
    private const EMAIL_PREFERENCE_COLUMN = [
        'lead' => 'email_on_new_lead',
        'reminder' => 'email_on_reminder',
    ];

    /**
     * `push` (default true) controls only the OUTBOUND channels — Web Push
     * and email. The in-app bell row is written either way, so a suppressed
     * alert (a stale lead, or muted alerts) is quiet without ever becoming
     * invisible on the dashboard. See NotificationDispatcher, which owns the
     * decision.
     *
     * @param array{level?: string, body?: ?string, url?: ?string, lead_id?: ?int, push?: bool} $opts
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

        if (($opts['push'] ?? true) === false) {
            return $notification;
        }

        // Fire a real Web Push too, so it lands on a locked / closed-browser
        // phone - not just the in-app bell. Best-effort; a push failure never
        // affects the notification row or the caller.
        try {
            app(WebPushService::class)->send($title, $opts['body'] ?? null, $opts['url'] ?? null, $type);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $this->emailFanOut($type, $title, $opts['body'] ?? null, $opts['url'] ?? null);
        } catch (\Throwable $e) {
            report($e);
        }

        return $notification;
    }

    /**
     * Per-member email, honouring each person's own email_on_* toggle and
     * quiet hours (P5 Notification preferences). A member who has never
     * opened that screen gets NotificationPreference::defaultsFor() —
     * every channel on — so this is opt-out, not opt-in silence by default.
     */
    private function emailFanOut(string $type, string $title, ?string $body, ?string $url): void
    {
        $column = self::EMAIL_PREFERENCE_COLUMN[$type] ?? null;
        $tenant = Tenancy::current();

        if ($column === null || $tenant === null) {
            return;
        }

        $prefsByUser = NotificationPreference::query()->get()->keyBy('user_id');
        $appUrl = (string) config('app.url');

        foreach ($tenant->users as $member) {
            $prefs = $prefsByUser->get($member->id) ?? NotificationPreference::defaultsFor($member->id);

            if (! $prefs->{$column} || $prefs->isQuietNow()) {
                continue;
            }

            Mail::to($member->email)->queue(new LeadNotificationMail($title, $body, $url, $appUrl));
        }
    }

    /**
     * A fresh, bid-worthy lead just landed. Deduped to one per lead so a
     * rescore can't double-notify.
     *
     * @deprecated Alerting decisions now belong to NotificationDispatcher,
     * which owns the score bar, freshness gate, mute switch, per-channel
     * dedupe and the shared message format. This raw fan-out is kept only
     * for the bell-only path and existing callers; new code should call
     * NotificationDispatcher::leadReady().
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
