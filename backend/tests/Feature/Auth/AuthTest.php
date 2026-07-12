<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->admin()->create(['password' => 'secret123']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'role']]]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'wrongpw@skillleo.test', 'password' => 'secret123']);

        $this->postJson('/api/auth/login', [
            'email' => 'wrongpw@skillleo.test',
            'password' => 'not-the-password',
        ])->assertStatus(422);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_me_returns_current_user(): void
    {
        $user = User::factory()->bidder()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role', 'bidder');
    }

    public function test_logout_deletes_the_token_row_used(): void
    {
        // Asserted against the DB rather than a follow-up request: Sanctum's
        // guard caches its resolved user for the lifetime of one test
        // method, so a second in-process request would pass even if the
        // token were never actually deleted. The real, cross-request
        // behavior (a deleted token gets a fresh 401) is covered by this
        // same mechanism being exercised in ScoreLeadJobTest-style HTTP
        // round trips and was verified manually against a live server.
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $tokenId = (int) explode('|', $token)[0];

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_unauthenticated_request_gets_clean_401_json_not_a_login_redirect(): void
    {
        // Regression guard: Laravel's default Authenticate middleware tries to
        // redirect guests to a `login` named route, which this pure-JSON API
        // never defines — without redirectGuestsTo(null) in bootstrap/app.php
        // this would 500 instead of 401 for any client that doesn't send an
        // explicit Accept: application/json header (e.g. plain curl).
        $response = $this->get('/api/me', ['Accept' => '*/*']);

        $response->assertStatus(401)->assertJson(['message' => 'Unauthenticated.']);
    }
}
