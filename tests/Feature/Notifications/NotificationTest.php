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

    public function test_index_returns_items_and_unread_count(): void
    {
        $user = User::factory()->bidder()->create();
        AppNotification::create(['type' => 'lead', 'level' => 'info', 'title' => 'Unread one']);
        AppNotification::create(['type' => 'lead', 'level' => 'info', 'title' => 'Already read', 'read_at' => now()]);

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/notifications')->assertOk();

        $this->assertCount(2, $res->json('data'));
        $this->assertSame(1, $res->json('meta.unread_count'));
    }

    public function test_mark_one_read(): void
    {
        $user = User::factory()->bidder()->create();
        $n1 = AppNotification::create(['type' => 'lead', 'level' => 'info', 'title' => 'A']);
        $n2 = AppNotification::create(['type' => 'lead', 'level' => 'info', 'title' => 'B']);

        $this->actingAs($user, 'sanctum')->postJson("/api/notifications/{$n1->id}/read")->assertOk();

        $this->assertNotNull($n1->fresh()->read_at);
        $this->assertNull($n2->fresh()->read_at);
    }

    public function test_mark_all_read(): void
    {
        $user = User::factory()->bidder()->create();
        AppNotification::create(['type' => 'lead', 'level' => 'info', 'title' => 'A']);
        AppNotification::create(['type' => 'reminder', 'level' => 'warning', 'title' => 'B']);

        $this->actingAs($user, 'sanctum')->postJson('/api/notifications/read-all')->assertOk();

        $this->assertSame(0, AppNotification::whereNull('read_at')->count());
    }

    public function test_notifications_require_auth(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
    }
}
