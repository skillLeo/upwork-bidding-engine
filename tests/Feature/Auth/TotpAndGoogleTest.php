<?php

namespace Tests\Feature\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use PragmaRX\Google2FALaravel\Google2FA;
use Tests\TestCase;

/**
 * P6 authenticator-app TOTP + Google OAuth, proven end to end (2026-07-24
 * phase pack).
 *
 * Authenticates via a REAL minted Sanctum token through asToken(), never
 * actingAs($user, 'sanctum') or a bare withToken() — see PlatformConsoleTest's
 * class docblock for the full reasoning (actingAs() pins a user onto the
 * guard directly; Sanctum's RequestGuard also caches the first-resolved user
 * on the guard object across simulated requests). Either one silently wins
 * over a later request's own Bearer token once a test moves between
 * identities — exactly what TOTP enrol-then-login and Google account-linking
 * both do.
 */
class TotpAndGoogleTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->admin()->create();
        \App\Tenancy\Tenancy::runAs($this->tenant, fn () => $owner->syncRoles(['owner']));
        $this->tenant->update(['owner_user_id' => $owner->id]);

        return $owner;
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /**
     * Auth::forgetGuards() before every withToken() — required any time a
     * test authenticates as more than one identity in sequence. Sanctum's
     * guard (a Laravel RequestGuard) caches the FIRST resolved user forever
     * on the guard object itself, which persists across simulated requests
     * within one test method — a later withToken() with a genuinely
     * different token's header otherwise silently resolves back to whoever
     * authenticated first. See PlatformConsoleTest's identical helper.
     */
    private function asToken(string $token): TestResponse|static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    /**
     * Swaps the Socialite facade for a mock that hands back $googleUser from
     * driver('google')->stateless()->user() — the exact call chain
     * SocialAuthController::callback() makes.
     */
    private function mockGoogleUser(string $id, string $email, string $name = 'Google User'): void
    {
        $googleUser = SocialiteUser::fake(['id' => $id, 'email' => $email, 'name' => $name]);

        $provider = \Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    private function enrollTotp(User $user): array
    {
        $google2fa = app(Google2FA::class);
        $token = $this->tokenFor($user);

        $enroll = $this->asToken($token)->postJson('/api/profile/totp/enroll')->assertOk();
        $secret = $enroll->json('data.secret');
        $code = $google2fa->getCurrentOtp($secret);

        $confirm = $this->asToken($token)->postJson('/api/profile/totp/confirm', ['code' => $code])->assertOk();

        return $confirm->json('data.recovery_codes');
    }

    // -------------------------------------------------------------- VERIFY a

    public function test_a_a_reused_totp_recovery_code_is_rejected(): void
    {
        $user = User::factory()->bidder()->create();
        $recoveryCodes = $this->enrollTotp($user);
        $code = $recoveryCodes[0];

        $login1 = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->assertTrue($login1->json('data.requires_totp'));

        $this->postJson('/api/auth/verify-totp', [
            'challenge' => $login1->json('data.challenge'),
            'code' => $code,
        ])->assertOk();

        // Second login, second challenge, the SAME recovery code — rejected.
        $login2 = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        $this->postJson('/api/auth/verify-totp', [
            'challenge' => $login2->json('data.challenge'),
            'code' => $code,
        ])->assertStatus(422);
    }

    // -------------------------------------------------------------- VERIFY b

    public function test_b_a_google_identity_matching_an_existing_email_cannot_link_without_the_password(): void
    {
        $existing = User::factory()->bidder()->create(['email' => 'shared@example.com']);
        $this->mockGoogleUser('google-shared-1', 'shared@example.com');

        $response = $this->get('/api/auth/google/callback');
        $response->assertRedirect();
        $location = $response->headers->get('Location');

        $this->assertStringContainsString('link_required=1', $location);
        $this->assertDatabaseMissing('social_accounts', ['provider_id' => 'google-shared-1']);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        // Wrong password: refused, no link created.
        $this->postJson('/api/auth/google/link', [
            'link_token' => $query['link_token'],
            'password' => 'definitely-wrong',
        ])->assertStatus(422);
        $this->assertDatabaseMissing('social_accounts', ['user_id' => $existing->id]);

        // Correct password: NOW it links.
        $this->mockGoogleUser('google-shared-1', 'shared@example.com'); // re-arm: callback() is not re-called, but keep the mock tidy
        $this->postJson('/api/auth/google/link', [
            'link_token' => $query['link_token'],
            'password' => 'password',
        ])->assertOk();
        $this->assertDatabaseHas('social_accounts', ['user_id' => $existing->id, 'provider_id' => 'google-shared-1']);
    }

    // -------------------------------------------------------------- VERIFY c

    public function test_c_require_2fa_blocks_every_route_until_enrolment_except_the_owner(): void
    {
        app(SettingsService::class)->set('require_2fa', true);
        $bidder = User::factory()->bidder()->create();
        $owner = $this->owner();
        $bidderToken = $this->tokenFor($bidder);

        $this->asToken($bidderToken)->getJson('/api/leads')
            ->assertStatus(403)
            ->assertJsonPath('code', 'must_enroll_2fa');
        $this->asToken($bidderToken)->getJson('/api/analytics')->assertStatus(403);

        // The small self-service allowlist stays reachable.
        $this->asToken($bidderToken)->getJson('/api/me')->assertOk();

        // The owner is never locked out by their own setting.
        $this->asToken($this->tokenFor($owner))->getJson('/api/leads')->assertOk();

        // Enrol, and the block lifts.
        $this->enrollTotp($bidder);
        $this->asToken($this->tokenFor($bidder->fresh()))->getJson('/api/leads')->assertOk();
    }

    // -------------------------------------------------------------- VERIFY d

    public function test_d_google_sign_in_does_not_bypass_the_workspaces_require_2fa_lock(): void
    {
        app(SettingsService::class)->set('require_2fa', true);

        $user = User::factory()->bidder()->create(['email' => 'newgoogle@example.com']);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'google-777', 'linked_at' => now()]);
        $this->mockGoogleUser('google-777', 'newgoogle@example.com');

        $response = $this->get('/api/auth/google/callback');
        $location = $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        // Google itself succeeds (no personal 2FA of their own to challenge)...
        $this->assertArrayHasKey('token', $query);

        // ...but the resulting session still cannot reach product routes,
        // because the WORKSPACE requires 2FA and this account has none.
        // OAuth is not a bypass for that lock either.
        $this->asToken($query['token'])->getJson('/api/leads')
            ->assertStatus(403)
            ->assertJsonPath('code', 'must_enroll_2fa');
    }

    public function test_google_sign_in_still_challenges_a_user_with_totp_already_enabled(): void
    {
        $user = User::factory()->bidder()->create(['email' => 'has2fa@example.com']);
        $this->enrollTotp($user);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'google-999', 'linked_at' => now()]);
        $this->mockGoogleUser('google-999', 'has2fa@example.com');

        $response = $this->get('/api/auth/google/callback');
        $location = $response->headers->get('Location');

        // Never a direct token — TOTP is checked FIRST, same branch a
        // password login uses.
        $this->assertStringContainsString('requires_totp=1', $location);
        $this->assertStringNotContainsString('token=', $location);
    }
}
