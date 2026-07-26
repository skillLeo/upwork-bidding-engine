<?php

namespace App\Authorization;

use App\Enums\AuthEventType;
use App\Exceptions\PlatformOwnerAlreadyExistsException;
use App\Models\AuthEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The single platform owner: assigned, transferred, and never duplicated.
 *
 * WHY THE APPLICATION LAYER AND NOT A UNIQUE INDEX.
 * "At most one row where platform_role = 'platform_owner'" is a PARTIAL
 * unique constraint. MySQL has no partial unique indexes, and the usual
 * fakes for one are worse than the disease:
 *
 *   - A plain UNIQUE on platform_role would also forbid two support staff
 *     and two billing staff, which is wrong.
 *   - A generated "owner_flag" column that is 1 for the owner and NULL for
 *     everyone else does work (NULLs are exempt from UNIQUE), but it stores
 *     a second, derived copy of the same truth that can silently drift out
 *     of step with the column it derives from.
 *
 * So the enforcement is: a named mutex, a transaction, a locking read of the
 * current holder, and a test that proves two concurrent attempts cannot both
 * win. All three parts matter —
 *
 *   THE LOCKING READ (lockForUpdate) makes the transfer case airtight: an
 *   existing owner's row is locked, so a second transaction touching it
 *   blocks until the first commits and then sees the new truth.
 *
 *   THE MUTEX covers the zero-owner case, which the row lock alone cannot.
 *   With no owner yet, the locking read matches no rows, and InnoDB gap
 *   locks are "purely inhibitive" — two transactions can BOTH hold a gap
 *   lock on the same gap and both see nothing, then each update a different
 *   user row with no conflict. Without the mutex, the very first assignment
 *   is the one race the row lock would miss.
 *
 * The mutex is a MySQL named lock (connection-scoped, released explicitly in
 * a finally, so a rollback can never strand it). On a driver without one the
 * code degrades to the transaction + row lock rather than pretending.
 */
class PlatformOwnership
{
    /**
     * Deliberately prefixed. MySQL named locks are server-global, not
     * per-database, so two apps sharing one MySQL instance would otherwise
     * block each other on a generic name.
     */
    private const MUTEX = 'skillleo:platform_owner';

    private const MUTEX_TIMEOUT_SECONDS = 10;

    public function current(): ?User
    {
        return User::query()->where('platform_role', PlatformRole::Owner->value)->first();
    }

    /**
     * Make $user the platform owner.
     *
     * Refused — loudly — if anyone else already holds it. Assigning it to the
     * account that already holds it is a no-op rather than an error, so a
     * re-run of a seeder or a migration is safe.
     *
     * @throws PlatformOwnerAlreadyExistsException
     */
    public function assign(User $user): User
    {
        return $this->withMutex(fn () => DB::transaction(function () use ($user) {
            $holder = $this->lockCurrentHolder();

            if ($holder !== null && (int) $holder->id !== (int) $user->id) {
                throw new PlatformOwnerAlreadyExistsException($holder);
            }

            $user->forceFill(['platform_role' => PlatformRole::Owner->value])->save();

            return $user->refresh();
        }));
    }

    /**
     * Move platform ownership from its current holder to $to.
     *
     * The swap is atomic — the outgoing owner is demoted and the incoming one
     * promoted inside one transaction, so there is no instant with two owners
     * and no instant with none. Password/2FA re-authentication is the
     * CALLER's job (see Platform\OwnershipController): this class owns the
     * invariant, the controller owns the challenge.
     */
    public function transfer(User $to, ?User $expectedCurrent = null): User
    {
        return $this->withMutex(fn () => DB::transaction(function () use ($to, $expectedCurrent) {
            $holder = $this->lockCurrentHolder();

            if ($holder === null) {
                throw new \RuntimeException('There is no platform owner to transfer from.');
            }

            // Guards against a stale request: the initiator re-authenticated
            // as the owner they believed they were, and ownership must not
            // have moved out from under them in between.
            if ($expectedCurrent !== null && (int) $holder->id !== (int) $expectedCurrent->id) {
                throw new \RuntimeException('Platform ownership has already moved to another account.');
            }

            if ((int) $holder->id === (int) $to->id) {
                throw new \RuntimeException('That account already holds platform ownership.');
            }

            $holder->forceFill(['platform_role' => null])->save();
            $to->forceFill(['platform_role' => PlatformRole::Owner->value])->save();

            AuthEvent::record(AuthEventType::PlatformOwnershipTransferred, user: $to);
            AuthEvent::record(AuthEventType::PlatformOwnershipTransferred, user: $holder);

            return $to->refresh();
        }));
    }

    /**
     * The current holder, read with a row lock so a concurrent transaction
     * touching the same row waits for this one to finish.
     */
    private function lockCurrentHolder(): ?User
    {
        return User::query()
            ->where('platform_role', PlatformRole::Owner->value)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $fn
     * @return TReturn
     */
    private function withMutex(\Closure $fn): mixed
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            return $fn();
        }

        $acquired = (int) $connection->selectOne(
            'select get_lock(?, ?) as got',
            [self::MUTEX, self::MUTEX_TIMEOUT_SECONDS],
        )->got;

        if ($acquired !== 1) {
            throw new \RuntimeException(
                'Could not acquire the platform-ownership lock — another ownership change is in flight.'
            );
        }

        try {
            return $fn();
        } finally {
            // Explicit, and in a finally: a named lock is connection-scoped,
            // NOT transaction-scoped, so a rollback would otherwise leave it
            // held for the life of the connection.
            $connection->statement('do release_lock(?)', [self::MUTEX]);
        }
    }
}
