<?php

namespace Tests\Feature\Auth;

use App\Mail\OtpCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OtpLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_without_two_factor_issues_a_token_immediately(): void
    {
        $user = User::factory()->create(['password' => 'secret123', 'two_factor_enabled' => false]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_login_with_two_factor_enabled_emails_a_code_instead_of_a_token(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => 'secret123', 'two_factor_enabled' => true]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.requires_otp', true)
            ->assertJsonStructure(['data' => ['challenge']])
            ->assertJsonMissingPath('data.token');

        Mail::assertSent(OtpCodeMail::class, fn ($mail) => $mail->hasTo($user->email));

        $user->refresh();
        $this->assertNotNull($user->two_factor_code);
        $this->assertNotNull($user->two_factor_challenge);
    }

    public function test_correct_otp_completes_login(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => 'secret123', 'two_factor_enabled' => true]);

        $challenge = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->json('data.challenge');

        $sentCode = null;
        Mail::assertSent(OtpCodeMail::class, function ($mail) use (&$sentCode) {
            $sentCode = $mail->code;

            return true;
        });

        $this->postJson('/api/auth/verify-otp', [
            'challenge' => $challenge,
            'code' => $sentCode,
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);

        $user->refresh();
        $this->assertNull($user->two_factor_code);
        $this->assertNull($user->two_factor_challenge);
    }

    public function test_wrong_otp_is_rejected(): void
    {
        Mail::fake();
        $user = User::factory()->create([
            'password' => 'secret123',
            'two_factor_enabled' => true,
            'two_factor_code' => Hash::make('123456'),
            'two_factor_challenge' => 'test-challenge',
            'two_factor_expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/auth/verify-otp', [
            'challenge' => 'test-challenge',
            'code' => '999999',
        ])->assertStatus(422);
    }

    public function test_expired_otp_is_rejected(): void
    {
        $user = User::factory()->create([
            'two_factor_code' => Hash::make('123456'),
            'two_factor_challenge' => 'expired-challenge',
            'two_factor_expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/auth/verify-otp', [
            'challenge' => 'expired-challenge',
            'code' => '123456',
        ])->assertStatus(422);
    }
}
