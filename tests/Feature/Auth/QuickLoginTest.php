<?php

namespace Tests\Feature\Auth;

use App\Authorization\PlatformRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The build-time quick-login shortcut, including the "Super admin" button.
 *
 * It is gated by skillleo.dev_quick_login and 404s when that is off, which
 * is what makes the shortcut safe to ship at all — the route exists but is
 * inert unless the deployment opts in. That gate is the only thing standing
 * between this endpoint and passwordless entry, so it gets its own test
 * rather than being assumed.
 */
class QuickLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['skillleo.dev_quick_login' => true]);
    }

    public function test_the_whole_endpoint_is_absent_when_the_flag_is_off(): void
    {
        config(['skillleo.dev_quick_login' => false]);

        User::factory()->create(['platform_role' => PlatformRole::Owner->value]);

        $this->postJson('/api/auth/dev-login', ['role' => 'platform_owner'])->assertStatus(404);
    }

    public function test_super_admin_signs_in_as_the_platform_owner_specifically(): void
    {
        // Deliberately NOT the oldest admin: the old shortcut picked the
        // oldest user with legacy role 'admin' and only hit the platform
        // owner by coincidence of ids. Here the platform owner is created
        // second, so "oldest admin" and "platform owner" are different people.
        $decoy = User::factory()->admin()->create(['email' => 'decoy@example.test']);
        $superAdmin = User::factory()->admin()->create([
            'email' => 'super@example.test',
            'platform_role' => PlatformRole::Owner->value,
        ]);

        $this->assertTrue($decoy->id < $superAdmin->id, 'the decoy must be the older admin');

        $response = $this->postJson('/api/auth/dev-login', ['role' => 'platform_owner'])->assertOk();

        $token = $response->json('data.token');
        $this->assertNotNull($token);

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'super@example.test')
            ->assertJsonPath('data.platform_role', PlatformRole::Owner->value);
    }

    public function test_it_says_so_plainly_when_no_platform_owner_exists(): void
    {
        User::factory()->admin()->create();

        $this->postJson('/api/auth/dev-login', ['role' => 'platform_owner'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_the_existing_admin_and_bidder_shortcuts_still_work(): void
    {
        User::factory()->admin()->create(['email' => 'admin@example.test']);
        User::factory()->bidder()->create(['email' => 'bidder@example.test']);

        foreach (['admin' => 'admin@example.test', 'bidder' => 'bidder@example.test'] as $role => $email) {
            Auth::forgetGuards();
            $token = $this->postJson('/api/auth/dev-login', ['role' => $role])->assertOk()->json('data.token');

            Auth::forgetGuards();
            $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.email', $email);
        }
    }

    public function test_an_unknown_target_is_refused(): void
    {
        $this->postJson('/api/auth/dev-login', ['role' => 'owner'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }
}
