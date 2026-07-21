<?php

namespace Tests\Feature\Notifications;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscribe_stores_a_deduped_subscription(): void
    {
        $user = User::factory()->create();
        $payload = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => 'PUBKEY', 'auth' => 'AUTHTOKEN'],
        ];

        $this->actingAs($user, 'sanctum')->postJson('/api/push/subscribe', $payload)->assertOk();
        $this->actingAs($user, 'sanctum')->postJson('/api/push/subscribe', $payload)->assertOk();

        $this->assertSame(1, PushSubscription::count());
        $this->assertDatabaseHas('push_subscriptions', ['endpoint_hash' => hash('sha256', $payload['endpoint'])]);
    }

    public function test_unsubscribe_removes_the_subscription(): void
    {
        $user = User::factory()->create();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/xyz';
        PushSubscription::create([
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'p256dh' => 'k',
            'auth_key' => 'a',
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/push/unsubscribe', ['endpoint' => $endpoint])->assertOk();

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_vapid_key_endpoint_reports_unconfigured_without_keys(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/push/vapid-key')
            ->assertOk()
            ->assertJsonPath('data.configured', false);
    }

    public function test_send_is_a_noop_when_unconfigured(): void
    {
        // No VAPID keys -> returns 0 before ever touching the crypto path.
        $this->assertSame(0, app(WebPushService::class)->send('Title', 'Body', '/leads'));
    }

    public function test_creating_a_notification_still_works_when_push_unconfigured(): void
    {
        // The web-push send is best-effort; the notification row must still be
        // created regardless.
        $notification = app(NotificationService::class)->push('system', 'Hello');

        $this->assertNotNull($notification->id);
        $this->assertDatabaseHas('app_notifications', ['title' => 'Hello']);
    }
}
