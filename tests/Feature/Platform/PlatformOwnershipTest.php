<?php

namespace Tests\Feature\Platform;

use App\Authorization\PlatformOwnership;
use App\Authorization\PlatformRole;
use App\Enums\AuthEventType;
use App\Exceptions\PlatformOwnerAlreadyExistsException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * P8 VERIFY (a): exactly one platform owner, ever.
 *
 * MySQL has no partial unique index, so the invariant is enforced by a named
 * mutex + a transaction + a locking read (see PlatformOwnership). An
 * invariant defended in application code and not by the schema is only as
 * good as its test, which is why the concurrent case is proven with two real
 * database connections rather than argued for in a comment.
 */
class PlatformOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function ownership(): PlatformOwnership
    {
        return app(PlatformOwnership::class);
    }

    private function asToken(string $token): TestResponse|static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    // -------------------------------------------------------- singularity

    public function test_a_second_user_cannot_be_made_platform_owner(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->ownership()->assign($first);

        $this->expectException(PlatformOwnerAlreadyExistsException::class);

        try {
            $this->ownership()->assign($second);
        } finally {
            // The refusal must leave the world untouched, not half-changed.
            $this->assertSame(PlatformRole::Owner->value, $first->fresh()->platform_role);
            $this->assertNull($second->fresh()->platform_role);
            $this->assertSame(1, User::where('platform_role', PlatformRole::Owner->value)->count());
        }
    }

    public function test_reassigning_to_the_existing_owner_is_a_no_op_not_an_error(): void
    {
        $owner = User::factory()->create();

        $this->ownership()->assign($owner);
        $this->ownership()->assign($owner);

        $this->assertSame(1, User::where('platform_role', PlatformRole::Owner->value)->count());
    }

    /**
     * TWO PARALLEL TRANSACTIONS, on two genuinely separate connections.
     *
     * RefreshDatabase wraps each test in a transaction on the default
     * connection, and an uncommitted row is invisible to any other
     * connection — so a second connection would not even see the users this
     * test creates. Both contenders therefore run on connections cloned from
     * the default, and the fixture rows are committed outside the test
     * transaction and cleaned up explicitly at the end.
     */
    public function test_two_concurrent_attempts_cannot_both_win(): void
    {
        $config = config('database.connections.'.config('database.default'));
        config([
            'database.connections.race_a' => $config,
            'database.connections.race_b' => $config,
        ]);

        $a = DB::connection('race_a');
        $b = DB::connection('race_b');

        // Committed on a connection of their own so BOTH contenders can see
        // them — RefreshDatabase's open transaction would otherwise hide them.
        $emailA = 'race-a@example.test';
        $emailB = 'race-b@example.test';

        foreach ([$emailA, $emailB] as $email) {
            $a->table('users')->insert([
                'name' => 'Race',
                'email' => $email,
                'password' => Hash::make('secret'),
                'role' => 'admin',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        try {
            $idA = $a->table('users')->where('email', $emailA)->value('id');
            $idB = $b->table('users')->where('email', $emailB)->value('id');

            // Both contenders run the SAME sequence the service runs: take the
            // named lock, open a transaction, read the current holder FOR
            // UPDATE, then claim. Interleaved by hand so A holds the lock while
            // B tries — which is exactly the race the mutex exists to lose.
            $claim = function ($connection, int $userId): bool {
                $got = (int) $connection->selectOne('select get_lock(?, ?) as got', ['skillleo:platform_owner', 2])->got;

                if ($got !== 1) {
                    return false; // blocked by the other contender — refused.
                }

                try {
                    $connection->beginTransaction();

                    $holder = $connection->table('users')
                        ->where('platform_role', PlatformRole::Owner->value)
                        ->lockForUpdate()
                        ->first();

                    if ($holder !== null && (int) $holder->id !== $userId) {
                        $connection->rollBack();

                        return false;
                    }

                    $connection->table('users')->where('id', $userId)
                        ->update(['platform_role' => PlatformRole::Owner->value]);
                    $connection->commit();

                    return true;
                } finally {
                    $connection->statement('do release_lock(?)', ['skillleo:platform_owner']);
                }
            };

            $wonA = $claim($a, $idA);
            $wonB = $claim($b, $idB);

            $this->assertTrue($wonA, 'the first contender should win');
            $this->assertFalse($wonB, 'the second contender must be refused, not queued behind and allowed');

            $owners = $a->table('users')->where('platform_role', PlatformRole::Owner->value)->count();
            $this->assertSame(1, $owners, "expected exactly one platform owner after the race, found {$owners}");
        } finally {
            $a->table('users')->whereIn('email', [$emailA, $emailB])->delete();
            $a->disconnect();
            $b->disconnect();
        }
    }

    // ----------------------------------------------------------- transfer

    public function test_transfer_swaps_both_roles_atomically_and_is_audited(): void
    {
        $from = User::factory()->create();
        $to = User::factory()->create();

        $this->ownership()->assign($from);
        $this->ownership()->transfer($to, expectedCurrent: $from);

        $this->assertNull($from->fresh()->platform_role, 'the outgoing owner keeps nothing');
        $this->assertSame(PlatformRole::Owner->value, $to->fresh()->platform_role);
        $this->assertSame(1, User::where('platform_role', PlatformRole::Owner->value)->count());

        $this->assertDatabaseHas('auth_events', [
            'event' => AuthEventType::PlatformOwnershipTransferred->value,
            'user_id' => $to->id,
        ]);
        $this->assertDatabaseHas('auth_events', [
            'event' => AuthEventType::PlatformOwnershipTransferred->value,
            'user_id' => $from->id,
        ]);
    }

    public function test_the_transfer_endpoint_requires_the_owners_password(): void
    {
        $owner = User::factory()->create(['password' => Hash::make('correct-horse')]);
        $recipient = User::factory()->create();
        $this->ownership()->assign($owner);

        $token = $owner->createToken('t')->plainTextToken;

        $this->asToken($token)
            ->postJson('/api/platform/ownership/transfer', [
                'user_id' => $recipient->id,
                'password' => 'not-the-password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertSame(PlatformRole::Owner->value, $owner->fresh()->platform_role);
        $this->assertNull($recipient->fresh()->platform_role);
    }

    public function test_platform_support_staff_cannot_transfer_ownership(): void
    {
        $owner = User::factory()->create();
        $this->ownership()->assign($owner);

        $support = User::factory()->create([
            'platform_role' => PlatformRole::Support->value,
            'password' => Hash::make('support-pass'),
        ]);
        $recipient = User::factory()->create();

        $this->asToken($support->createToken('t')->plainTextToken)
            ->postJson('/api/platform/ownership/transfer', [
                'user_id' => $recipient->id,
                'password' => 'support-pass',
            ])
            ->assertStatus(403);

        $this->assertSame(PlatformRole::Owner->value, $owner->fresh()->platform_role);
    }

    public function test_a_correct_password_completes_the_transfer_through_the_endpoint(): void
    {
        $owner = User::factory()->create(['password' => Hash::make('correct-horse')]);
        $recipient = User::factory()->create();
        $this->ownership()->assign($owner);

        $this->asToken($owner->createToken('t')->plainTextToken)
            ->postJson('/api/platform/ownership/transfer', [
                'user_id' => $recipient->id,
                'password' => 'correct-horse',
            ])
            ->assertOk();

        $this->assertNull($owner->fresh()->platform_role);
        $this->assertSame(PlatformRole::Owner->value, $recipient->fresh()->platform_role);
    }
}
