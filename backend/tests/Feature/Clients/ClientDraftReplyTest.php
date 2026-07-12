<?php

namespace Tests\Feature\Clients;

use App\Enums\ClientStage;
use App\Models\Client;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientDraftReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_client(): void
    {
        $client = Client::factory()->create();

        $this->getJson("/api/clients/{$client->id}")->assertStatus(401);
    }

    public function test_shows_client_with_message_history(): void
    {
        $bidder = User::factory()->bidder()->create();
        $client = Client::factory()->stage(ClientStage::Talking)->create();

        $this->actingAs($bidder, 'sanctum')
            ->getJson("/api/clients/{$client->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonPath('data.stage', 'talking');
    }

    public function test_draft_reply_saves_inbound_message_and_flags_needs_hassam(): void
    {
        app(SettingsService::class)->setMany(['openclaw_url' => 'https://openclaw.test', 'openclaw_token' => 'tok']);

        Http::fake([
            'openclaw.test/*' => Http::response(['reply' => 'Sure, my rate is...', 'needs_hassam' => true]),
        ]);

        $bidder = User::factory()->bidder()->create();
        $client = Client::factory()->create();

        $response = $this->actingAs($bidder, 'sanctum')->postJson("/api/clients/{$client->id}/draft-reply", [
            'message' => 'What is your rate for a long-term contract?',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.drafted_reply', 'Sure, my rate is...')
            ->assertJsonPath('data.needs_hassam', true)
            ->assertJsonPath('data.direction', 'in');

        $this->assertDatabaseHas('messages', [
            'client_id' => $client->id,
            'text' => 'What is your rate for a long-term contract?',
            'needs_hassam' => true,
        ]);
    }

    public function test_draft_reply_degrades_gracefully_when_openclaw_fails(): void
    {
        app(SettingsService::class)->setMany(['openclaw_url' => 'https://openclaw.test', 'openclaw_token' => 'tok']);

        Http::fake(['openclaw.test/*' => Http::response([], 500)]);

        $bidder = User::factory()->bidder()->create();
        $client = Client::factory()->create();

        $response = $this->actingAs($bidder, 'sanctum')->postJson("/api/clients/{$client->id}/draft-reply", [
            'message' => 'Hello there',
        ]);

        // The inbound message must still be saved even though drafting failed —
        // never lose the client's message just because the AI call failed.
        $response->assertOk()->assertJsonPath('data.drafted_reply', null);
        $this->assertDatabaseHas('messages', ['client_id' => $client->id, 'text' => 'Hello there']);
    }
}
