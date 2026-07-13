<?php

namespace Tests\Feature;

use App\Models\SavedFilter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_saved_filters(): void
    {
        $this->getJson('/api/saved-filters')->assertStatus(401);
    }

    public function test_bidder_can_create_a_saved_filter(): void
    {
        $bidder = User::factory()->bidder()->create();

        $response = $this->actingAs($bidder, 'sanctum')->postJson('/api/saved-filters', [
            'name' => 'PHP Jobs',
            'is_pinned' => true,
            'criteria' => ['include_keywords' => ['PHP', 'Laravel']],
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'PHP Jobs');
        $this->assertDatabaseHas('saved_filters', ['name' => 'PHP Jobs']);
    }

    public function test_only_one_filter_can_be_default_at_a_time(): void
    {
        $bidder = User::factory()->bidder()->create();
        $first = SavedFilter::factory()->create(['is_default' => true]);

        $this->actingAs($bidder, 'sanctum')->postJson('/api/saved-filters', [
            'name' => 'New Default',
            'is_default' => true,
            'criteria' => [],
        ])->assertCreated();

        $this->assertFalse($first->fresh()->is_default);
        $this->assertDatabaseHas('saved_filters', ['name' => 'New Default', 'is_default' => true]);
    }

    public function test_filter_name_must_be_unique(): void
    {
        $bidder = User::factory()->bidder()->create();
        SavedFilter::factory()->create(['name' => 'Existing']);

        $this->actingAs($bidder, 'sanctum')->postJson('/api/saved-filters', [
            'name' => 'Existing',
            'criteria' => [],
        ])->assertStatus(422);
    }

    public function test_bidder_can_update_and_delete_a_filter(): void
    {
        $bidder = User::factory()->bidder()->create();
        $filter = SavedFilter::factory()->create(['name' => 'Old Name']);

        $this->actingAs($bidder, 'sanctum')
            ->putJson("/api/saved-filters/{$filter->id}", ['name' => 'New Name', 'criteria' => []])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->actingAs($bidder, 'sanctum')
            ->deleteJson("/api/saved-filters/{$filter->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('saved_filters', ['id' => $filter->id]);
    }
}
