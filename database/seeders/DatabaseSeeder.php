<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Everything below writes tenant-owned rows, and the tenant scope throws
     * rather than silently writing an ownerless row — so a tenant has to be
     * bound first. On a real deploy the data migration creates tenant 1; on
     * `migrate:fresh --seed` there are no rows to adopt, so the workspace is
     * created here instead.
     */
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => config('tenancy.default_tenant_slug', 'skillleo')],
            ['name' => 'SkillLeo', 'plan' => 'internal', 'status' => Tenant::STATUS_ACTIVE],
        );

        app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
            $this->call([
                UserSeeder::class,
                SettingsSeeder::class,
                MailSettingsSeeder::class,
                TemplateSeeder::class,
                LeadSeeder::class,
            ]);

            // UserSeeder has run, so there is now an admin to own the
            // workspace and members to attach to it.
            $admin = \App\Models\User::where('role', 'admin')->orderBy('id')->first();

            if ($admin !== null && $tenant->owner_user_id === null) {
                $tenant->update(['owner_user_id' => $admin->id]);
            }

            foreach (\App\Models\User::pluck('id') as $userId) {
                $tenant->users()->syncWithoutDetaching([$userId => ['joined_at' => now()]]);
            }
        });

        // Provision the four roles for this workspace and assign the seeded
        // users. The RBAC data migration handles an EXISTING install (tenant
        // already there when it runs); on migrate:fresh the tenant is created
        // above, after migrations, so the seeder does it.
        app(\App\Authorization\RoleProvisioner::class)->provision($tenant->fresh());

        \App\Tenancy\Tenancy::runAs($tenant->fresh(), function () use ($tenant) {
            foreach (\App\Models\User::whereIn('id', $tenant->users()->pluck('users.id'))->get() as $user) {
                $role = $tenant->owner_user_id === $user->id
                    ? \App\Authorization\TenantRole::Owner
                    : ($user->role?->value === 'admin' ? \App\Authorization\TenantRole::Admin : \App\Authorization\TenantRole::Bidder);

                $user->syncRoles([$role->value]);
            }
        });
    }
}
