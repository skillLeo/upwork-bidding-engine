<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

/**
 * Sends real Web Push notifications - the only mechanism that reaches a phone
 * when the browser is fully closed / the screen is locked. Best-effort: it
 * never throws into a caller (a notification is a nicety, not a critical path),
 * and it prunes dead subscriptions as the push service reports them.
 */
class WebPushService
{
    public function __construct(protected SettingsService $settings) {}

    public function configured(): bool
    {
        return trim((string) $this->settings->get('vapid_public_key')) !== ''
            && trim((string) $this->settings->get('vapid_private_key')) !== '';
    }

    public function publicKey(): string
    {
        return (string) $this->settings->get('vapid_public_key');
    }

    /**
     * Generate a VAPID keypair once and persist it. Idempotent-ish: callers
     * decide whether to overwrite.
     *
     * @return array{publicKey: string, privateKey: string}
     */
    public function generateKeys(): array
    {
        $keys = VAPID::createVapidKeys();

        $this->settings->setMany([
            'vapid_public_key' => $keys['publicKey'],
            'vapid_private_key' => $keys['privateKey'],
        ]);

        return $keys;
    }

    /**
     * Notification "type" -> the NotificationPreference column that gates
     * push for it. Kept in sync with NotificationService::EMAIL_PREFERENCE_COLUMN's
     * mapping of the same two real types.
     */
    private const PUSH_PREFERENCE_COLUMN = [
        'lead' => 'push_on_new_lead',
        'reminder' => 'push_on_reminder',
    ];

    /**
     * Push to every subscribed device, minus anyone this send's $type says
     * to skip (their own push_on_* preference off, or their quiet hours).
     * Returns the number delivered. No-ops (before touching any crypto) when
     * unconfigured or there are no subscriptions - which is why tests
     * without subscriptions never load the gmp/bcmath-heavy code path.
     *
     * $type is optional and defaults to "send to everyone" (unattributed
     * subscriptions with no user_id ALWAYS receive everything — see the
     * add_user_id_to_push_subscriptions migration for why that's the safe,
     * backward-compatible default rather than a silent drop).
     */
    public function send(string $title, ?string $body, ?string $url, ?string $type = null): int
    {
        if (! $this->configured()) {
            return 0;
        }

        $subscriptions = PushSubscription::all();

        if ($subscriptions->isEmpty()) {
            return 0;
        }

        $column = self::PUSH_PREFERENCE_COLUMN[$type] ?? null;

        if ($column !== null) {
            $userIds = $subscriptions->pluck('user_id')->filter()->unique();
            $prefsByUser = NotificationPreference::query()->whereIn('user_id', $userIds)->get()->keyBy('user_id');

            $subscriptions = $subscriptions->filter(function (PushSubscription $sub) use ($column, $prefsByUser) {
                if ($sub->user_id === null) {
                    return true;
                }

                $prefs = $prefsByUser->get($sub->user_id) ?? NotificationPreference::defaultsFor($sub->user_id);

                return $prefs->{$column} && ! $prefs->isQuietNow();
            })->values();
        }

        if ($subscriptions->isEmpty()) {
            return 0;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => 'mailto:'.($this->settings->get('mail_from_address') ?: 'admin@skillleo.com'),
                'publicKey' => $this->publicKey(),
                'privateKey' => (string) $this->settings->get('vapid_private_key'),
            ],
        ]);

        $payload = json_encode([
            'title' => $title,
            'body' => $body ?? '',
            'url' => $url ?: '/leads',
        ]);

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'keys' => ['p256dh' => $subscription->p256dh, 'auth' => $subscription->auth_key],
                    'contentEncoding' => $subscription->content_encoding ?: 'aes128gcm',
                ]),
                $payload,
            );
        }

        $delivered = 0;

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $delivered++;

                continue;
            }

            // 404/410 => the subscription is gone; drop it so we stop trying.
            if ($report->isSubscriptionExpired()) {
                PushSubscription::query()
                    ->where('endpoint_hash', hash('sha256', $report->getEndpoint()))
                    ->delete();
            }
        }

        return $delivered;
    }
}
