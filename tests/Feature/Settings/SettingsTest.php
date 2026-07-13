<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_settings(): void
    {
        $this->getJson('/api/settings')->assertStatus(401);
    }

    public function test_bidder_cannot_access_settings(): void
    {
        $bidder = User::factory()->bidder()->create();

        $this->actingAs($bidder, 'sanctum')
            ->getJson('/api/settings')
            ->assertStatus(403);

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/settings', ['score_cutoff' => 5])
            ->assertStatus(403);
    }

    public function test_admin_can_save_and_read_back_masked_settings(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/settings', [
            'claude_api_key' => 'sk-ant-abcdef123456',
            'score_cutoff' => 8,
        ])->assertOk();

        $get = $this->actingAs($admin, 'sanctum')->getJson('/api/settings');

        $get->assertOk()
            ->assertJsonPath('data.ai.claude_api_key.is_set', true)
            ->assertJsonPath('data.rules.score_cutoff', 8);

        $masked = $get->json('data.ai.claude_api_key.masked');
        $this->assertStringNotContainsString('abcdef123456', $masked);
        $this->assertStringEndsWith('3456', $masked);
    }

    public function test_secret_values_are_encrypted_at_rest_but_decrypt_correctly(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/settings', [
            'claude_api_key' => 'sk-ant-plaintext-should-not-appear',
        ]);

        $row = Setting::query()->where('key', 'claude_api_key')->first();

        $this->assertNotNull($row);
        $this->assertTrue($row->is_secret);
        $this->assertStringNotContainsString('sk-ant-plaintext-should-not-appear', (string) $row->value);

        $this->assertEquals(
            'sk-ant-plaintext-should-not-appear',
            app(SettingsService::class)->claudeApiKey(),
        );
    }

    public function test_blank_secret_field_does_not_overwrite_existing_value(): void
    {
        $admin = User::factory()->admin()->create();
        app(SettingsService::class)->set('claude_api_key', 'existing-key');

        $this->actingAs($admin, 'sanctum')->postJson('/api/settings', [
            'claude_api_key' => '',
            'score_cutoff' => 9,
        ])->assertOk();

        $this->assertEquals('existing-key', app(SettingsService::class)->claudeApiKey());
        $this->assertEquals(9, app(SettingsService::class)->get('score_cutoff'));
    }

    public function test_non_secret_rule_is_stored_unencrypted(): void
    {
        app(SettingsService::class)->set('score_cutoff', 6);

        $row = Setting::query()->where('key', 'score_cutoff')->first();

        $this->assertFalse($row->is_secret);
        $this->assertEquals('6', $row->value);
    }

    public function test_settings_cache_is_invalidated_on_write(): void
    {
        $service = app(SettingsService::class);
        $this->assertEquals(7, $service->get('score_cutoff')); // schema default, warms cache

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')->postJson('/api/settings', ['score_cutoff' => 3]);

        $this->assertEquals(3, $service->get('score_cutoff'));
    }
}
