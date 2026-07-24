<?php

namespace Tests\Feature\Auth;

use App\Authorization\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P7's one backend addition: public self-serve sign-up, reachable ONLY when
 * signup_mode is "open". The default is invite_code, so it is inert until an
 * admin opens it — these tests pin both halves of that gate.
 */
class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_signup_provisions_a_workspace_with_the_registrant_as_owner(): void
    {
        app(SettingsService::class)->set('signup_mode', 'open');

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Dana Rivers',
            'email' => 'dana@northwind.test',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
            'workspace_name' => 'Northwind Studio',
        ])->assertCreated();

        $this->assertNotEmpty($response->json('data.token'));

        $user = User::where('email', 'dana@northwind.test')->firstOrFail();
        $tenant = $user->tenants()->firstOrFail();

        $this->assertSame('Northwind Studio', $tenant->name);
        $this->assertSame($user->id, $tenant->owner_user_id);
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);

        // The registrant holds the owner role inside their new workspace.
        // Asserted against the pivot directly rather than hasRole(), because
        // this shared test process keeps an earlier tenant bound and Spatie's
        // cached hasRole() resolves against that other team.
        $ownerRoleId = \Illuminate\Support\Facades\DB::table('roles')
            ->where('name', TenantRole::Owner->value)
            ->where('tenant_id', $tenant->id)
            ->value('id');
        $this->assertNotNull($ownerRoleId, 'the new workspace has its own owner role');
        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $ownerRoleId,
            'model_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_a_slug_collision_is_resolved_uniquely(): void
    {
        app(SettingsService::class)->set('signup_mode', 'open');

        foreach (['a@x.test', 'b@x.test'] as $email) {
            $this->postJson('/api/auth/register', [
                'name' => 'Someone',
                'email' => $email,
                'password' => 'a-strong-password',
                'password_confirmation' => 'a-strong-password',
                'workspace_name' => 'Studio',
            ])->assertCreated();
        }

        $slugs = Tenancy::asPlatform(fn () => Tenant::where('name', 'Studio')->pluck('slug')->all());
        $this->assertCount(2, array_unique($slugs), 'two same-named workspaces must get distinct slugs');
    }

    public function test_register_is_refused_when_signup_is_not_open(): void
    {
        foreach (['invite_code', 'closed'] as $mode) {
            app(SettingsService::class)->set('signup_mode', $mode);

            $this->postJson('/api/auth/register', [
                'name' => 'Nope',
                'email' => "nope-{$mode}@x.test",
                'password' => 'a-strong-password',
                'password_confirmation' => 'a-strong-password',
                'workspace_name' => 'Blocked',
            ])->assertStatus(422);

            $this->assertDatabaseMissing('users', ['email' => "nope-{$mode}@x.test"]);
        }
    }

    public function test_open_signup_rejects_a_duplicate_email(): void
    {
        app(SettingsService::class)->set('signup_mode', 'open');
        User::factory()->create(['email' => 'taken@x.test']);

        $this->postJson('/api/auth/register', [
            'name' => 'Dup',
            'email' => 'taken@x.test',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
            'workspace_name' => 'Dup Studio',
        ])->assertStatus(422);
    }
}
