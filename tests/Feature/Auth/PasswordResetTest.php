<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_a_reset_notification_for_a_real_email(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_does_not_leak_whether_the_email_exists(): void
    {
        Notification::fake();

        // Same success response for an email that isn't registered - the
        // point is not revealing account existence to an anonymous caller.
        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@skillleo.test'])
            ->assertOk()
            ->assertJsonPath('data.message', 'If that email is registered, a reset link is on its way.');

        Notification::assertNothingSent();
    }

    public function test_reset_url_points_at_the_frontend_not_a_named_web_route(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, function (ResetPassword $notification) {
            $mail = $notification->toMail(User::factory()->make());

            return str_contains($mail->actionUrl, config('skillleo.frontend_url').'/reset-password?token=');
        });
    }

    public function test_valid_token_resets_the_password_and_revokes_existing_tokens(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $oldToken = $user->createToken('dashboard')->plainTextToken;
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));

        $tokenId = (int) explode('|', $oldToken)[0];
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422);
    }
}
