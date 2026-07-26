<?php

use App\Models\Tenant;
use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pin every EXISTING workspace's stacks, project facts and signature as its
 * own rows, before the code defaults for those keys become empty.
 *
 * THE BUG THIS CLOSES. Those keys defaulted to the founding user's real
 * values — his stack lists, his entire project track record, his first name,
 * his intake mailbox. Settings resolve tenant → platform → schema default, so
 * a workspace that had never touched them inherited HIS. A new workspace
 * scored leads against his definition of in-scope work (rejecting jobs in its
 * own speciality) and, far worse, could claim his projects as its own inside
 * a proposal sent to a stranger.
 *
 * Emptying the defaults fixes that for every future workspace. This migration
 * is what stops it changing anything for the workspaces that already exist:
 * each one is handed an explicit row holding the value it is using today, so
 * the deploy is a no-op from where its owner is sitting.
 *
 * The old defaults are embedded below rather than read from the schema,
 * because by the time this runs the schema no longer has them. A data
 * migration that preserves a specific historical state has to carry that
 * state.
 */
return new class extends Migration
{
    /**
     * Exactly what SettingsService::SCHEMA returned for these keys before
     * this change. Do not "tidy" this list — it is a historical record.
     */
    private const PREVIOUS_DEFAULTS = [
        'core_stacks' => [
            'PHP', 'Laravel', 'Vue', 'Flutter', 'Odoo', 'Python', 'Django', 'FastAPI', 'Flask',
            'Next.js', 'TypeScript', 'React Native', 'Dart', 'Kotlin', 'HTML', 'CSS',
            'MySQL', 'PostgreSQL', 'Firebase',
        ],
        'secondary_stacks' => [
            'Node.js', 'React', 'JavaScript', 'Express', 'jQuery', 'MongoDB', 'MERN', 'REST API',
        ],
        'excluded_stacks' => [
            'Go', 'Golang', 'Ruby', 'Ruby on Rails', 'Rust', 'Angular', '.NET', 'Java', 'Spring Boot',
            'Symfony', 'Kubernetes', 'Redis', 'Docker', 'AWS Lambda', 'GraphQL', 'WordPress',
            'Magento', 'Shopify', 'Wix', 'Redux', 'Vuex', 'Nuxt', 'Svelte',
        ],
        'stack_keywords' => ['Laravel', 'PHP', 'Next.js', 'React', 'Node', 'MERN', 'Flutter', 'Python'],
        'proposal_signature' => 'Hassam',
        'gmail_address' => 'skillleopvt@gmail.com',
    ];

    public function up(): void
    {
        // project_facts is handled separately: its previous default is ~4KB of
        // prose, and the founding workspace already has its own row for it on
        // production. Only a workspace genuinely relying on the default needs
        // one written, and there is no honest value to invent for anyone else.
        $projectFactsDefault = $this->previousProjectFactsDefault();

        // TENANCY: this walks every workspace on purpose — it is pinning each
        // one's inherited value as its own before the inheritance disappears.
        Tenancy::asPlatform(function () use ($projectFactsDefault) {
            $tenants = Tenant::withTrashed()->orderBy('id')->get();

            if ($tenants->isEmpty()) {
                echo '  No workspaces yet — nothing to pin.'.PHP_EOL;

                return;
            }

            $written = 0;

            foreach ($tenants as $tenant) {
                $pinned = [];

                foreach (self::PREVIOUS_DEFAULTS as $key => $previousDefault) {
                    if ($this->hasOwnRow($tenant->id, $key)) {
                        continue; // Already explicit — it was never inheriting.
                    }

                    $this->writeRow($tenant->id, $key, $previousDefault, group: $this->groupFor($key));
                    $pinned[] = $key;
                    $written++;
                }

                if (! $this->hasOwnRow($tenant->id, 'project_facts') && $projectFactsDefault !== null) {
                    $this->writeRow($tenant->id, 'project_facts', $projectFactsDefault, group: 'ai');
                    $pinned[] = 'project_facts';
                    $written++;
                }

                echo sprintf(
                    '  %s (id %d): %s'.PHP_EOL,
                    $tenant->slug,
                    $tenant->id,
                    $pinned === [] ? 'already had its own values — untouched.' : 'pinned '.implode(', ', $pinned),
                );
            }

            echo PHP_EOL."  Rows written: {$written}.".PHP_EOL;
        });

        app(SettingsService::class)->forgetAllCaches();
    }

    public function down(): void
    {
        // Not reversed. Deleting these rows would hand every workspace back
        // to a default that no longer exists, leaving them with empty stacks.
    }

    /**
     * The founding user's fact sheet, as it was hardcoded. Returned only if
     * some workspace is actually still inheriting it — otherwise null, so
     * this never plants his track record anywhere it isn't already.
     */
    private function previousProjectFactsDefault(): ?string
    {
        $existing = DB::table('settings')->where('key', 'project_facts')->orderBy('id')->first();

        if ($existing === null) {
            return null;
        }

        return json_decode($existing->value, true);
    }

    private function hasOwnRow(int $tenantId, string $key): bool
    {
        return DB::table('settings')->where('tenant_id', $tenantId)->where('key', $key)->exists();
    }

    private function writeRow(int $tenantId, string $key, mixed $value, string $group): void
    {
        DB::table('settings')->insert([
            'tenant_id' => $tenantId,
            'key' => $key,
            'value' => json_encode($value),
            'group' => $group,
            'is_secret' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function groupFor(string $key): string
    {
        return match ($key) {
            'proposal_signature' => 'ai',
            'gmail_address' => 'vollna',
            default => 'rules',
        };
    }
};
