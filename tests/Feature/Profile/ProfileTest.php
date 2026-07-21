<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_update_profile(): void
    {
        $this->putJson('/api/profile', ['name' => 'X', 'email' => 'x@x.com'])->assertStatus(401);
    }

    public function test_bidder_is_forbidden_from_profile_management(): void
    {
        // Account management is admin-only; the bidder account is locked to
        // the leads workflow (also hidden in the SPA nav + router).
        $bidder = User::factory()->bidder()->create();

        $this->actingAs($bidder, 'sanctum')
            ->putJson('/api/profile', ['name' => 'X', 'email' => 'x@skillleo.test'])
            ->assertStatus(403);

        $this->actingAs($bidder, 'sanctum')
            ->putJson('/api/profile/two-factor', ['enabled' => true])
            ->assertStatus(403);
    }

    public function test_name_only_change_does_not_require_current_password(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile', ['name' => 'New Name', 'email' => $user->email])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name']);
    }

    public function test_changing_email_without_current_password_is_rejected(): void
    {
        $user = User::factory()->admin()->create(['email' => 'old@skillleo.test']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile', ['name' => $user->name, 'email' => 'new-email@skillleo.test'])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'old@skillleo.test']);
    }

    public function test_changing_email_with_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->admin()->create(['email' => 'old@skillleo.test', 'password' => 'correct-password']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => $user->name,
                'email' => 'new-email@skillleo.test',
                'current_password' => 'wrong-password',
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'old@skillleo.test']);
    }

    public function test_changing_email_with_correct_current_password_succeeds(): void
    {
        $user = User::factory()->admin()->create(['email' => 'old@skillleo.test', 'password' => 'correct-password']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => $user->name,
                'email' => 'new-email@skillleo.test',
                'current_password' => 'correct-password',
            ])
            ->assertOk()
            ->assertJsonPath('data.email', 'new-email@skillleo.test');
    }

    public function test_email_must_be_unique_across_other_users(): void
    {
        $other = User::factory()->admin()->create(['email' => 'taken@skillleo.test']);
        $user = User::factory()->admin()->create(['password' => 'correct-password']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => $user->name,
                'email' => 'taken@skillleo.test',
                'current_password' => 'correct-password',
            ])
            ->assertStatus(422);
    }

    public function test_updating_own_profile_with_the_same_email_is_allowed(): void
    {
        $user = User::factory()->admin()->create(['email' => 'me@skillleo.test']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile', ['name' => 'Still Me', 'email' => 'me@skillleo.test'])
            ->assertOk();
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->admin()->create(['password' => 'correct-password']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile/password', [
                'current_password' => 'wrong-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertStatus(422);

        $this->assertTrue(Hash::check('correct-password', $user->fresh()->password));
    }

    public function test_correct_current_password_updates_and_revokes_other_tokens(): void
    {
        $user = User::factory()->admin()->create(['password' => 'correct-password']);
        $otherToken = $user->createToken('other-device')->plainTextToken;
        $otherTokenId = (int) explode('|', $otherToken)[0];

        $currentToken = $user->createToken('this-request');

        $this->withHeader('Authorization', "Bearer {$currentToken->plainTextToken}")
            ->putJson('/api/profile/password', [
                'current_password' => 'correct-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherTokenId]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $currentToken->accessToken->id]);
    }

    public function test_user_can_upload_an_avatar(): void
    {
        Storage::fake('public');
        $user = User::factory()->admin()->create();
        $file = UploadedFile::fake()->image('avatar.jpg');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/avatar', ['avatar' => $file])
            ->assertOk();

        $path = $user->fresh()->avatar_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($response->json('data.avatar_url'));
    }

    public function test_non_image_avatar_upload_is_rejected(): void
    {
        Storage::fake('public');
        $user = User::factory()->admin()->create();
        $file = UploadedFile::fake()->create('not-an-image.pdf', 100);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/avatar', ['avatar' => $file])
            ->assertStatus(422);
    }

    public function test_svg_avatar_upload_is_rejected(): void
    {
        // SVG can carry an inline <script> - explicitly excluded even
        // though Laravel's generic `image` rule would normally allow it.
        Storage::fake('public');
        $user = User::factory()->admin()->create();
        $file = UploadedFile::fake()->create('avatar.svg', 10, 'image/svg+xml');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/avatar', ['avatar' => $file])
            ->assertStatus(422);
    }

    public function test_user_can_toggle_two_factor(): void
    {
        $user = User::factory()->admin()->create(['two_factor_enabled' => false]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile/two-factor', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.two_factor_enabled', true);

        $this->assertTrue($user->fresh()->two_factor_enabled);
    }
}
