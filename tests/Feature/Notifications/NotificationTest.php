<?php

namespace Tests\Feature\Notifications;

use App\Models\AppNotification;
use App\Models\Lead;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_ready_creates_a_deduped_notification(): void
    {
        $lead = Lead::factory()->ready(9)->create();
        $service = app(NotificationService::class);

        $first = $service->leadReady($lead);
        $second = $service->leadReady($lead); // same lead again

        $this->assertNotNull($first);
        $this->assertNull($second, 'A second call for the same lead must not create a duplicate.');
        $this->assertSame(1, AppNotification::where('lead_id', $lead->id)->count());
        $this->assertSame('lead', $first->type);
        $this->assertStringContainsString('9/10', $first->title);
        $this->assertSame("/leads/{$lead->id}", $first->url);
    }

    public function test_index_returns_items_and_a_per_user_unread_count(): void
    {
        // A fresh user has read nothing, so both notifications are unread FOR
        // THEM regardless of any other user's state.
        $user = User::factory()->bidder()->create();
        AppNotification::create(['type' => 'lead', 'level' => 'info', 'title' => 'One']);
        AppNotification::create(['type' => 'lead', 'level' => 'info', 'title' => 'Two']);

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/notifications')->assertOk();

        $this->assertCount(2, $res->json('data'));
        $this->assertSame(2, $res->json('meta.unread_count'));
    }

    public function test_mark_one_read_is_per_user(): void
    {
        $user = User::factory()->bidder()->create();
        $n1 = AppNotification::create(['type' => 'lead', 'level' => 'info', 'title' => 'A']);
        AppNotification::create(['type' => 'lead', 'level' => 'info', 'title' => 'B']);

        $this->actingAs($user, 'sanctum')->postJson("/api/notifications/{$n1->id}/read")->assertOk();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/notifications')->assertOk();
        $this->assertSame(1, $res->json('meta.unread_count'));
        $this->assertDatabaseHas('app_notification_reads', [
            'app_notification_id' => $n1->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_mark_all_read_only_affects_the_acting_user(): void
    {
        $user = User::factory()->bidder()->create();
        AppNotification::create(['type' => 'lead', 'level' => 'info', 'title' => 'A']);
        AppNotification::create(['type' => 'reminder', 'level' => 'warning', 'title' => 'B']);

        $this->actingAs($user, 'sanctum')->postJson('/api/notifications/read-all')->assertOk();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/notifications')->assertOk();
        $this->assertSame(0, $res->json('meta.unread_count'));
    }

    /**
     * Verify (d): two users have independent notification read state. The old
     * shared read_at meant one person opening the bell cleared it for
     * everyone; this proves that is fixed.
     */
    public function test_two_users_have_independent_read_state(): void
    {
        $alice = User::factory()->bidder()->create();
        $bob = User::factory()->bidder()->create();
        $n = AppNotification::create(['type' => 'lead', 'level' => 'info', 'title' => 'Shared notice']);

        // Alice reads it.
        $this->actingAs($alice, 'sanctum')->postJson("/api/notifications/{$n->id}/read")->assertOk();

        // Alice now has 0 unread; Bob still has 1 — the same notification.
        $this->assertSame(0, $this->actingAs($alice, 'sanctum')->getJson('/api/notifications')->json('meta.unread_count'));
        $this->assertSame(1, $this->actingAs($bob, 'sanctum')->getJson('/api/notifications')->json('meta.unread_count'));
    }

    public function test_notifications_require_auth(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
    }
}
