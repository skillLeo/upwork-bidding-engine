<?php

namespace Tests\Feature\Settings;

use App\Authorization\RoleProvisioner;
use App\Exceptions\AiQuotaExceededException;
use App\Models\AiCall;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\AiManager;
use App\Services\Ai\AiQuotaService;
use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * P8 VERIFY (d) and (e): the AI provider credentials and model IDs belong to
 * the platform, and every workspace's calls are billed to them while being
 * metered against that workspace's OWN quota.
 *
 * The distinction those two halves draw is the whole point of pooled keys:
 * shared credential, private limit. A test that only proved the shared half
 * would be describing a way to let one workspace spend another's budget.
 */
class PlatformAiCustodyTest extends TestCase
{
    use RefreshDatabase;

    private const PLATFORM_KEYS = [
        'ai_provider',
        'anthropic_api_key',
        'openai_api_key',
        'scoring_model',
        'proposal_model',
        'review_model',
    ];

    private function asToken(User $user): TestResponse|static
    {
        Auth::forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    private function owner(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $tenant->users()->syncWithoutDetaching([$user->id => ['joined_at' => now()]]);
        Tenancy::runAs($tenant, fn () => $user->syncRoles(['owner']));
        $tenant->update(['owner_user_id' => $user->id]);

        return $user;
    }

    /** Write a platform-layer row the way the migration and the console do. */
    private function setPlatform(array $values): void
    {
        app(SettingsService::class)->setManyOnPlatformLayer($values);
    }

    // ------------------------------------------------------- custody itself

    public function test_all_six_keys_are_declared_platform_only(): void
    {
        $declared = (array) config('tenancy.platform_only_keys');

        foreach (self::PLATFORM_KEYS as $key) {
            $this->assertContains($key, $declared, "{$key} must be platform-only");
        }
    }

    public function test_a_workspace_cannot_write_a_tenant_override_for_a_pooled_key(): void
    {
        $customer = Tenant::create([
            'name' => 'Customer', 'slug' => 'customer', 'plan' => 'free', 'status' => Tenant::STATUS_ACTIVE,
        ]);

        Tenancy::runAs($customer, function () {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('[anthropic_api_key] is a platform-level setting');

            app(SettingsService::class)->set('anthropic_api_key', 'sk-ant-customer-key');
        });
    }

    public function test_the_pooled_keys_are_absent_from_the_tenant_settings_payload(): void
    {
        app(RoleProvisioner::class)->provision($this->tenant);
        $owner = $this->owner($this->tenant);

        $response = $this->asToken($owner)->getJson('/api/settings')->assertOk();
        $body = $response->json('data');

        // Flattened: the payload is grouped, and "absent" has to mean absent
        // from every group, not merely from the one it used to live in.
        $flat = collect($body)->flatMap(fn ($group) => is_array($group) ? array_keys($group) : [])->all();

        foreach ([...self::PLATFORM_KEYS, 'scoring_system_prompt', 'proposal_skill', 'mail_host', 'signup_mode'] as $key) {
            $this->assertNotContains($key, $flat, "{$key} must not reach a workspace owner");
        }

        // The workspace's own AI-adjacent settings are still there — this is
        // custody, not a blanket lockout.
        $this->assertContains('project_facts', $flat);
        $this->assertContains('proposal_signature', $flat);
    }

    public function test_posting_a_pooled_key_to_the_tenant_settings_endpoint_changes_nothing(): void
    {
        app(RoleProvisioner::class)->provision($this->tenant);
        $owner = $this->owner($this->tenant);
        $this->setPlatform(['anthropic_api_key' => 'sk-ant-platform']);

        $this->asToken($owner)->postJson('/api/settings', [
            'anthropic_api_key' => 'sk-ant-hijacked',
            'scoring_model' => 'claude-opus-4-8',
        ])->assertOk();

        $this->assertSame('sk-ant-platform', app(SettingsService::class)->platform('anthropic_api_key'));
        $this->assertSame(0, Setting::withoutGlobalScopes()->whereIn('key', self::PLATFORM_KEYS)->whereNotNull('tenant_id')->count());
    }

    // ------------------------------------------------------- VERIFY (e)

    public function test_a_second_tenants_ai_call_uses_the_platform_key_and_that_tenants_own_quota(): void
    {
        $this->setPlatform([
            'ai_provider' => 'anthropic',
            'anthropic_api_key' => 'sk-ant-pooled',
            'scoring_model' => 'claude-haiku-4-5',
        ]);

        $second = Tenant::create([
            'name' => 'Second', 'slug' => 'second', 'plan' => 'free', 'status' => Tenant::STATUS_ACTIVE,
        ]);

        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);

        Tenancy::runAs($second, function () {
            app(SettingsService::class)->set('ai_monthly_token_cap', 1_000_000);

            app(AiManager::class)->complete('scoring', 'system', 'user', 'claude-haiku-4-5', 100);
        });

        // The POOLED key went out on the wire, for a tenant that has never
        // held a credential of its own.
        Http::assertSent(fn ($request) => $request->header('x-api-key')[0] === 'sk-ant-pooled');

        // And the spend landed on THAT tenant's ledger, not the platform's.
        $call = AiCall::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame($second->id, (int) $call->tenant_id);
    }

    public function test_a_second_tenants_own_cap_stops_its_calls_without_touching_anyone_else(): void
    {
        $this->setPlatform(['ai_provider' => 'anthropic', 'anthropic_api_key' => 'sk-ant-pooled']);

        $second = Tenant::create([
            'name' => 'Capped', 'slug' => 'capped', 'plan' => 'free', 'status' => Tenant::STATUS_ACTIVE,
        ]);

        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);

        Tenancy::runAs($second, function () use ($second) {
            app(SettingsService::class)->setMany([
                'ai_monthly_token_cap' => 100,
                'ai_hard_stop_on_cap' => true,
            ]);

            AiCall::create([
                'tenant_id' => $second->id,
                'purpose' => 'scoring',
                'provider' => 'anthropic',
                'model' => 'claude-haiku-4-5',
                'input_tokens' => 5_000,
                'output_tokens' => 5_000,
                'cost_usd' => 0.05,
                'duration_ms' => 10,
                'success' => true,
            ]);

            $this->expectException(AiQuotaExceededException::class);

            app(AiManager::class)->complete('scoring', 'system', 'user', 'claude-haiku-4-5', 100);
        });

        // Refused BEFORE the provider — a capped workspace never spends a
        // token of the pooled budget.
        Http::assertNothingSent();

        // The founding workspace, which has no cap, is unaffected.
        $this->assertFalse(
            Tenancy::runAs($this->tenant, fn () => app(AiQuotaService::class)->isOverCap()),
        );
    }

    public function test_the_platform_reader_refuses_a_tenant_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[min_budget] is not a platform-level setting');

        app(SettingsService::class)->platform('min_budget');
    }

    public function test_the_platform_reader_ignores_a_stray_tenant_row(): void
    {
        $this->setPlatform(['anthropic_api_key' => 'sk-ant-pooled']);

        // A row that cannot be created through the app at all — simulating a
        // bad restore or a hand-edited table. platform() must not see it.
        Setting::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'key' => 'anthropic_api_key',
            'value' => Crypt::encryptString(json_encode('sk-ant-stray')),
            'group' => 'ai',
            'is_secret' => true,
        ]);

        app(SettingsService::class)->forgetAllCaches();

        $this->assertSame('sk-ant-pooled', app(SettingsService::class)->platform('anthropic_api_key'));
    }
}
