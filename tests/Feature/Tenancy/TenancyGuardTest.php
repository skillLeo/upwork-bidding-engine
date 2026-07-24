<?php

namespace Tests\Feature\Tenancy;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\BelongsToTenantOrPlatform;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Isolation enforced by a test that fails in CI rather than by discipline.
 *
 * A missed tenant predicate is the one bug class that ends a multi-tenant
 * product, and it is invisible until a customer sees another customer's
 * data. Code review does not reliably catch it. This does.
 *
 * The assertion that matters most is not the per-model one — it is
 * test_every_tenant_owned_table_is_declared_here, which fails when someone
 * adds a table next year and forgets this file exists.
 */
class TenancyGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * THE list. Every model that owns tenant data.
     *
     * @var array<int, class-string<Model>>
     */
    private const TENANT_MODELS = [
        \App\Models\ActivityLog::class,
        \App\Models\AiCall::class,
        \App\Models\AppNotification::class,
        \App\Models\Client::class,
        \App\Models\DeletedLeadExternalId::class,
        \App\Models\Invitation::class,
        \App\Models\Lead::class,
        \App\Models\Message::class,
        \App\Models\NotificationPreference::class,
        \App\Models\ProposalVersion::class,
        \App\Models\PushSubscription::class,
        \App\Models\SavedFilter::class,
        \App\Models\Setting::class,
        \App\Models\Template::class,
    ];

    /**
     * Tables that legitimately have no tenant_id, with the reason. Anything
     * else carrying a tenant_id column must appear in TENANT_MODELS.
     *
     * @var array<string, string>
     */
    private const NON_TENANT_TABLES = [
        'tenants' => 'the table the scope is derived from',
        'tenant_users' => 'the tenant/user pivot itself',
        'users' => 'a user can belong to several workspaces (tenant_users is the link)',
        'migrations' => 'framework',
        'password_reset_tokens' => 'framework auth, keyed by email',
        'personal_access_tokens' => 'framework auth; tenant_id records issuing workspace only (P3)',
        'auth_events' => 'security audit log — deliberately unscoped: a failed login has no tenant, '
            .'and scoping would hide exactly the rows an investigation needs. tenant_id is recorded '
            .'when known and filtered explicitly at query sites. See AuthEvent.',
        'sessions' => 'framework',
        'cache' => 'framework',
        'cache_locks' => 'framework',
        'jobs' => 'one shared queue on this host by design',
        'job_batches' => 'one shared queue on this host by design',
        'failed_jobs' => 'one shared queue on this host by design',
        // Spatie permission tables. tenant_id here is Spatie's TEAM key, not
        // our BelongsToTenant scope — role/permission assignments are scoped
        // by team through TenantTeamResolver, which reads the same
        // TenantContext. Managed entirely by the package, never by the trait.
        'roles' => 'Spatie roles, team-scoped by tenant_id via TenantTeamResolver',
        'permissions' => 'Spatie permissions, global (not team-scoped)',
        'model_has_roles' => 'Spatie pivot, tenant_id is the team key',
        'model_has_permissions' => 'Spatie pivot, tenant_id is the team key',
        'role_has_permissions' => 'Spatie pivot',
        'app_notification_reads' => 'per-user read state; keyed by (notification, user), the notification carries the scope',
        'permission_denies' => 'per-user permission denies; composite (tenant,user,permission) key written explicitly, '
            .'and read inside Gate::before where the global scope would throw. See PermissionDeny.',
        'social_accounts' => 'keyed by user, not tenant (P6) — a Google identity is workspace-independent, same as a password',
    ];

    /**
     * settings is the one table where tenant_id is nullable BY DESIGN — a
     * null row is the platform default every tenant falls back to.
     */
    private const NULLABLE_TENANT_ID = ['settings'];

    private function tenantOf(string $slug): Tenant
    {
        return Tenant::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug), 'plan' => 'trial', 'status' => Tenant::STATUS_ACTIVE],
        );
    }

    public function test_every_declared_model_uses_a_tenancy_trait(): void
    {
        foreach (self::TENANT_MODELS as $class) {
            $traits = class_uses_recursive($class);

            $this->assertTrue(
                in_array(BelongsToTenant::class, $traits, true)
                || in_array(BelongsToTenantOrPlatform::class, $traits, true),
                "[{$class}] is declared tenant-owned but uses neither BelongsToTenant nor BelongsToTenantOrPlatform. "
                .'Without the trait every query on it is unscoped and every tenant sees every row.'
            );
        }
    }

    public function test_every_declared_model_has_a_tenant_id_column_that_is_not_nullable(): void
    {
        foreach (self::TENANT_MODELS as $class) {
            $table = (new $class)->getTable();

            $this->assertTrue(
                Schema::hasColumn($table, 'tenant_id'),
                "[{$table}] has no tenant_id column."
            );

            if (in_array($table, self::NULLABLE_TENANT_ID, true)) {
                continue;
            }

            $this->assertFalse(
                $this->columnIsNullable($table),
                "[{$table}].tenant_id is nullable. A null there is a row that belongs to nobody and is "
                .'invisible to every tenant forever.'
            );
        }
    }

    public function test_every_declared_model_has_an_index_leading_with_tenant_id(): void
    {
        foreach (self::TENANT_MODELS as $class) {
            $table = (new $class)->getTable();
            $leading = [];

            foreach (Schema::getIndexes($table) as $index) {
                $columns = $index['columns'] ?? [];
                if (($columns[0] ?? null) === 'tenant_id') {
                    $leading[] = $index['name'];
                }
            }

            $this->assertNotEmpty(
                $leading,
                "[{$table}] has no index whose FIRST column is tenant_id. Every query on this table now "
                .'carries a tenant predicate, so without one it degrades to a full scan.'
            );
        }
    }

    public function test_each_model_returns_none_of_another_tenants_rows(): void
    {
        $a = $this->tenantOf('guard-a');
        $b = $this->tenantOf('guard-b');
        $context = app(TenantContext::class);

        foreach (self::TENANT_MODELS as $class) {
            $context->runAs($b, fn () => $this->seedOne($class));

            $countForA = $context->runAs($a, fn () => $class::count());

            $this->assertSame(
                0,
                $countForA,
                "[{$class}] returned {$countForA} of tenant B's rows while tenant A was bound."
            );
        }
    }

    public function test_creating_stamps_the_bound_tenant_without_being_passed_one(): void
    {
        $a = $this->tenantOf('guard-a');

        foreach (self::TENANT_MODELS as $class) {
            $model = app(TenantContext::class)->runAs($a, fn () => $this->seedOne($class));

            $this->assertSame(
                $a->id,
                $model->tenant_id,
                "[{$class}] was created without tenant_id being stamped from the bound tenant."
            );
        }
    }

    /**
     * The assertion that catches the table someone adds next year: any table
     * in the database carrying a tenant_id column must be represented above,
     * or it is scoped by nothing at all.
     */
    public function test_every_tenant_owned_table_is_declared_here(): void
    {
        $declared = array_map(fn (string $c) => (new $c)->getTable(), self::TENANT_MODELS);
        $tables = Schema::getTableListing(schema: null, schemaQualified: false);

        // Guard the guard: if the listing ever comes back empty (a driver
        // change, a schema-qualification quirk) this test would pass while
        // checking nothing at all.
        $this->assertNotEmpty($tables, 'no tables were listed — this check would be silently vacuous');

        foreach ($tables as $table) {
            if (in_array($table, $declared, true) || array_key_exists($table, self::NON_TENANT_TABLES)) {
                continue;
            }

            $this->assertFalse(
                Schema::hasColumn($table, 'tenant_id'),
                "[{$table}] has a tenant_id column but is missing from TenancyGuardTest::TENANT_MODELS. "
                .'Add its model there (with a tenancy trait), or add the table to NON_TENANT_TABLES with a reason.'
            );
        }
    }

    /**
     * Every deliberate bypass of the tenant scope must be labelled, so that
     * `grep -rn "TENANCY:"` is a complete and honest list of the places
     * isolation is intentionally set aside.
     */
    public function test_no_unlabelled_scope_bypass_exists_in_the_codebase(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // The tenancy layer itself defines the bypass; it cannot be
            // required to justify itself to its own guard.
            if (str_contains($file->getPathname(), '/Tenancy/')
                || str_contains($file->getPathname(), '/Concerns/')) {
                continue;
            }

            $lines = file($file->getPathname());

            foreach ($lines as $i => $line) {
                $isBypass = str_contains($line, 'withoutGlobalScope')
                    || str_contains($line, 'asPlatform(')
                    || preg_match('/DB::table\(/', $line) === 1;

                if (! $isBypass) {
                    continue;
                }

                // Justified if a TENANCY: comment appears in the 6 lines above.
                $window = implode('', array_slice($lines, max(0, $i - 6), 6));

                if (! str_contains($window, 'TENANCY:')) {
                    $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.($i + 1).' — '.trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Un-labelled tenant-scope bypass(es) found. Each needs an inline comment starting with \"TENANCY:\" "
            ."explaining why it is safe:\n  ".implode("\n  ", $offenders)
        );
    }

    // ------------------------------------------------------------------ helpers

    private function columnIsNullable(string $table): bool
    {
        foreach (Schema::getColumns($table) as $column) {
            if ($column['name'] === 'tenant_id') {
                return (bool) $column['nullable'];
            }
        }

        $this->fail("[{$table}] has no tenant_id column to inspect.");
    }

    /**
     * Minimum viable row per model — factories do not exist for all of them.
     */
    private function seedOne(string $class): Model
    {
        return match ($class) {
            \App\Models\ActivityLog::class => \App\Models\ActivityLog::create(['type' => 'lead_received']),
            \App\Models\AiCall::class => \App\Models\AiCall::create(['purpose' => 'scoring', 'provider' => 'anthropic', 'model' => 'm']),
            \App\Models\AppNotification::class => \App\Models\AppNotification::create(['type' => 'lead', 'title' => 't', 'body' => 'b']),
            \App\Models\Client::class => \App\Models\Client::factory()->create(),
            \App\Models\DeletedLeadExternalId::class => \App\Models\DeletedLeadExternalId::create(['external_id' => 'x'.uniqid()]),
            \App\Models\Invitation::class => \App\Models\Invitation::create([
                'email' => uniqid().'@example.com', 'role' => 'bidder',
                'token_hash' => hash('sha256', uniqid()), 'expires_at' => now()->addDay(),
            ]),
            \App\Models\Lead::class => \App\Models\Lead::factory()->create(),
            \App\Models\Message::class => \App\Models\Message::create([
                'client_id' => \App\Models\Client::factory()->create()->id,
                'direction' => 'in', 'text' => 'hello',
            ]),
            \App\Models\NotificationPreference::class => \App\Models\NotificationPreference::create([
                'user_id' => \App\Models\User::factory()->create()->id,
            ]),
            \App\Models\ProposalVersion::class => \App\Models\ProposalVersion::create([
                'lead_id' => \App\Models\Lead::factory()->create()->id,
                'version_number' => 1, 'body' => 'text', 'edit_type' => 'generated',
            ]),
            \App\Models\PushSubscription::class => \App\Models\PushSubscription::create([
                'endpoint' => $e = 'https://fcm.googleapis.com/'.uniqid(),
                'endpoint_hash' => hash('sha256', $e), 'p256dh' => 'k', 'auth_key' => 'a',
            ]),
            \App\Models\SavedFilter::class => \App\Models\SavedFilter::factory()->create(),
            \App\Models\Setting::class => \App\Models\Setting::create([
                'key' => 'k'.uniqid(), 'value' => '1', 'group' => 'rules', 'is_secret' => false,
            ]),
            \App\Models\Template::class => \App\Models\Template::factory()->create(),
            default => $this->fail("No seed defined for [{$class}] in TenancyGuardTest::seedOne()."),
        };
    }
}
