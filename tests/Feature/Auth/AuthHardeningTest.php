<?php

namespace Tests\Feature\Auth;

use App\Enums\AuthEventType;
use App\Jobs\SendNewDeviceAlertJob;
use App\Models\AuthEvent;
use App\Models\User;
use App\Services\Auth\DeviceLabeller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // throttle counters are cache-backed
    }

    private function user(array $attrs = []): User
    {
        return User::factory()->admin()->create(array_merge([
            'email' => 'hassam@example.com',
            'password' => 'correct-horse',
        ], $attrs));
    }

    // ---------------------------------------------------------- (a) absolute

    public function test_a_token_older_than_the_absolute_expiry_is_rejected(): void
    {
        config(['sanctum.expiration' => 60 * 24 * 30]); // 30 days

        $user = $this->user();
        $token = $user->createToken('dashboard');

        // Aged past 30 days. last_used_at moves with it so this proves the
        // ABSOLUTE rule specifically, not idleness. (The accepted case is a
        // separate test: making two requests here would not re-validate,
        // because the guard caches the resolved user for the process.)
        $token->accessToken->forceFill([
            'created_at' => now()->subDays(31),
            'last_used_at' => now()->subMinute(),
        ])->save();

        $this->withToken($token->plainTextToken)->getJson('/api/me')->assertUnauthorized();
    }

    // -------------------------------------------------------------- (b) idle

    public function test_a_token_idle_beyond_fourteen_days_is_rejected(): void
    {
        $user = $this->user();
        $token = $user->createToken('dashboard');

        // Issued recently, so the absolute rule is nowhere near — only
        // idleness can reject this.
        $token->accessToken->forceFill([
            'created_at' => now()->subDays(20),
            'last_used_at' => now()->subDays(15),
        ])->save();

        $this->withToken($token->plainTextToken)->getJson('/api/me')->assertUnauthorized();

        // Enforced at the moment of use: the row is gone, not merely ignored.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_a_token_used_yesterday_is_still_accepted(): void
    {
        $user = $this->user();
        $token = $user->createToken('dashboard');

        $token->accessToken->forceFill([
            'created_at' => now()->subDays(20),
            'last_used_at' => now()->subDay(),
        ])->save();

        $this->withToken($token->plainTextToken)->getJson('/api/me')->assertOk();
    }

    public function test_a_token_never_used_is_judged_on_its_issue_date(): void
    {
        $user = $this->user();
        $token = $user->createToken('dashboard');

        // Never used, minted 15 days ago — otherwise a null last_used_at
        // would mean a token that can never expire by idleness.
        $token->accessToken->forceFill([
            'created_at' => now()->subDays(15),
            'last_used_at' => null,
        ])->save();

        $this->withToken($token->plainTextToken)->getJson('/api/me')->assertUnauthorized();
    }

    // ----------------------------------------------------------- (c) lockout

    public function test_the_sixth_login_attempt_within_a_minute_is_locked_out(): void
    {
        $this->user();

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'hassam@example.com',
                'password' => 'wrong',
            ])->assertStatus(422);
        }

        // 6th is refused before the password is even checked.
        $sixth = $this->postJson('/api/auth/login', [
            'email' => 'hassam@example.com',
            'password' => 'wrong',
        ])->assertStatus(429);

        $this->assertStringContainsString('Too many sign-in attempts', $sixth->json('message'));

        // And the CORRECT password is refused too while locked — otherwise
        // the lock is decorative.
        $this->postJson('/api/auth/login', [
            'email' => 'hassam@example.com',
            'password' => 'correct-horse',
        ])->assertStatus(429);
    }

    public function test_a_successful_login_clears_the_failure_counter(): void
    {
        $this->user();

        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'hassam@example.com', 'password' => 'wrong']);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'hassam@example.com',
            'password' => 'correct-horse',
        ])->assertOk();

        // Three more failures must not immediately re-lock: the counter reset.
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'hassam@example.com', 'password' => 'wrong'])
                ->assertStatus(422);
        }
    }

    public function test_a_failed_login_never_reveals_whether_the_email_exists(): void
    {
        $this->user();

        $real = $this->postJson('/api/auth/login', ['email' => 'hassam@example.com', 'password' => 'wrong']);
        $fake = $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong']);

        $this->assertSame($real->status(), $fake->status());
        $this->assertSame($real->json('errors.email'), $fake->json('errors.email'));
    }

    // -------------------------------------------------------- (e) auth_events

    public function test_auth_events_records_a_success_a_failure_and_a_logout(): void
    {
        $user = $this->user();

        $this->postJson('/api/auth/login', ['email' => 'hassam@example.com', 'password' => 'wrong'])
            ->assertStatus(422);

        $token = $this->postJson('/api/auth/login', [
            'email' => 'hassam@example.com',
            'password' => 'correct-horse',
        ])->assertOk()->json('data.token');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $events = AuthEvent::orderBy('id')->pluck('event')->map(fn ($e) => $e->value)->all();

        $this->assertSame([
            AuthEventType::LoginFailed->value,
            AuthEventType::LoginSuccess->value,
            AuthEventType::Logout->value,
        ], $events);

        // The failed attempt records the address tried even though no user
        // matched — that is how credential stuffing becomes visible.
        $failed = AuthEvent::where('event', AuthEventType::LoginFailed->value)->first();
        $this->assertSame('hassam@example.com', $failed->email_attempted);
        $this->assertNull($failed->user_id);
    }

    public function test_the_audit_log_cannot_be_edited_or_deleted(): void
    {
        $user = $this->user();
        $event = AuthEvent::record(AuthEventType::LoginSuccess, user: $user);

        $this->expectException(\RuntimeException::class);
        $event->update(['ip' => '1.2.3.4']);
    }

    public function test_the_audit_log_cannot_be_deleted(): void
    {
        $user = $this->user();
        $event = AuthEvent::record(AuthEventType::LoginSuccess, user: $user);

        $this->expectException(\RuntimeException::class);
        $event->delete();
    }

    public function test_a_password_change_is_audited(): void
    {
        $user = $this->user();
        $token = $user->createToken('dashboard')->plainTextToken;

        $this->withToken($token)->putJson('/api/profile/password', [
            'current_password' => 'correct-horse',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->assertDatabaseHas('auth_events', [
            'user_id' => $user->id,
            'event' => AuthEventType::PasswordChanged->value,
        ]);
    }

    // ------------------------------------------------------- device metadata

    public function test_a_new_token_captures_device_metadata(): void
    {
        $this->user();

        $token = $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        ])->postJson('/api/auth/login', [
            'email' => 'hassam@example.com',
            'password' => 'correct-horse',
        ])->assertOk()->json('data.token');

        $row = PersonalAccessToken::findToken($token);

        $this->assertSame('Chrome on macOS', $row->device_label);
        $this->assertNotNull($row->ip_address);
        $this->assertNotNull($row->user_agent);
    }

    public function test_the_device_labeller_prefers_the_most_specific_token(): void
    {
        $labeller = new DeviceLabeller;

        // Edge advertises itself as Chrome AND Safari; Chrome advertises
        // itself as Safari. Order in the lookup table is what makes these
        // right, so each is asserted explicitly.
        $this->assertSame('Edge on Windows', $labeller->label(
            'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36 Edg/120'
        ));
        $this->assertSame('Safari on iPhone', $labeller->label(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1'
        ));
        $this->assertNull($labeller->label(null));
    }

    // ----------------------------------------------------------- new device

    public function test_a_login_from_an_unseen_device_queues_an_alert(): void
    {
        Queue::fake();
        $user = $this->user();

        // Existing history, so this is not the first-ever sign-in (which is
        // signup, and deliberately silent).
        $user->createToken('old')->accessToken->forceFill([
            'ip_address' => '203.0.113.9',
            'user_agent' => 'Old Browser',
        ])->save();

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120'])
            ->postJson('/api/auth/login', ['email' => 'hassam@example.com', 'password' => 'correct-horse'])
            ->assertOk();

        Queue::assertPushed(SendNewDeviceAlertJob::class);
    }

    public function test_the_first_ever_login_does_not_send_a_new_device_alert(): void
    {
        Queue::fake();
        $this->user();

        $this->postJson('/api/auth/login', ['email' => 'hassam@example.com', 'password' => 'correct-horse'])
            ->assertOk();

        // Signup is not a security event; alerting here trains people to
        // ignore the email.
        Queue::assertNotPushed(SendNewDeviceAlertJob::class);
    }

    // -------------------------------------------------------------- sessions

    public function test_a_user_sees_only_their_own_sessions(): void
    {
        $mine = $this->user();
        $theirs = User::factory()->create(['email' => 'other@example.com']);
        $theirs->createToken('theirs');

        $token = $mine->createToken('mine')->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/profile/sessions')->assertOk();

        $this->assertCount(1, $res->json('data.sessions'));
        $this->assertTrue($res->json('data.sessions.0.is_current'));
    }

    public function test_a_user_cannot_revoke_someone_elses_session(): void
    {
        $mine = $this->user();
        $theirs = User::factory()->create(['email' => 'other@example.com']);
        $victim = $theirs->createToken('theirs')->accessToken;

        $token = $mine->createToken('mine')->plainTextToken;

        $this->withToken($token)->deleteJson("/api/profile/sessions/{$victim->id}")->assertNotFound();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $victim->id]);
    }

    public function test_sign_out_everywhere_else_keeps_the_current_session(): void
    {
        $user = $this->user();
        $user->createToken('phone');
        $user->createToken('tablet');
        $current = $user->createToken('laptop')->plainTextToken;

        $this->withToken($current)->deleteJson('/api/profile/sessions/others')
            ->assertOk()
            ->assertJsonPath('data.revoked', 2);

        // Still signed in on this device.
        $this->withToken($current)->getJson('/api/me')->assertOk();
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_the_email_revoke_link_requires_a_valid_signature(): void
    {
        $user = $this->user();
        $user->createToken('phone');

        // Unsigned URL is refused outright.
        $this->getJson("/api/auth/revoke-all/{$user->id}")->assertStatus(403);
        $this->assertSame(1, $user->tokens()->count());

        $signed = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'auth.revoke-all',
            now()->addDays(7),
            ['user' => $user->id],
        );

        $this->getJson($signed)->assertOk();
        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    // ------------------------------------------------------------- OTP cap

    public function test_five_wrong_otp_codes_destroy_the_challenge(): void
    {
        $user = $this->user(['two_factor_enabled' => true]);

        $challenge = $this->postJson('/api/auth/login', [
            'email' => 'hassam@example.com',
            'password' => 'correct-horse',
        ])->assertOk()->json('data.challenge');

        $this->assertNotNull($challenge);

        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/auth/verify-otp', ['challenge' => $challenge, 'code' => '000000'])
                ->assertStatus(422);
        }

        // The 5th wrong code kills the challenge rather than merely failing.
        $this->postJson('/api/auth/verify-otp', ['challenge' => $challenge, 'code' => '000000'])
            ->assertStatus(422);

        $this->assertNull($user->fresh()->two_factor_challenge);
    }

    // ------------------------------------------------------------- pruning

    public function test_the_prune_command_removes_dead_tokens_only(): void
    {
        $user = $this->user();

        $live = $user->createToken('live')->accessToken;

        $expired = $user->createToken('expired')->accessToken;
        $expired->forceFill(['created_at' => now()->subDays(31), 'last_used_at' => now()])->save();

        $idle = $user->createToken('idle')->accessToken;
        $idle->forceFill(['created_at' => now()->subDays(20), 'last_used_at' => now()->subDays(15)])->save();

        $this->artisan('auth:prune-tokens')->assertSuccessful();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $live->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $expired->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $idle->id]);
    }
}
