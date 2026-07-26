<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use App\Tenancy\Tenancy;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Bidder,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * The full-access member of the bound workspace.
     *
     * Grants OWNER since P8 removed the tenant 'admin' role — a workspace now
     * has one owner and everyone else is a bidder or a viewer. The state
     * keeps the name `admin()` because that is what dozens of existing tests
     * call it and because the legacy users.role column really does still say
     * 'admin' for a workspace owner; only the Spatie role moved.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
        ])->afterCreating(fn (User $user) => $this->assignTenantRole($user, 'owner'));
    }

    /** Read-only member. */
    public function viewer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Bidder,
        ])->afterCreating(fn (User $user) => $this->assignTenantRole($user, 'viewer'));
    }

    public function bidder(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Bidder,
        ])->afterCreating(fn (User $user) => $this->assignTenantRole($user, 'bidder'));
    }

    /**
     * Attach the user to the currently-bound tenant and give them the Spatie
     * role matching the legacy column, so a factory-built admin/bidder can
     * actually pass the permission checks in tests exactly as a real member
     * would. No-op when no tenant is bound (pure-unit contexts).
     */
    private function assignTenantRole(User $user, string $role): void
    {
        $tenant = app(TenantContext::class)->get();

        if ($tenant === null) {
            return;
        }

        $tenant->users()->syncWithoutDetaching([$user->id => ['joined_at' => now()]]);

        // The role rows only exist once the tenant has been provisioned; the
        // base TestCase does that in setUp before any factory runs.
        if (Role::where('name', $role)->exists()) {
            Tenancy::runAs($tenant, fn () => $user->syncRoles([$role]));
        }
    }
}
