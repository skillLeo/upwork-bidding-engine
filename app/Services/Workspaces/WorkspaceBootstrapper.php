<?php

namespace App\Services\Workspaces;

use App\Jobs\BackfillWorkspaceFromPoolJob;
use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Services\SettingsService;
use App\Tenancy\Tenancy;

/**
 * The two things that have to happen for a brand-new workspace to be usable
 * the moment its owner first signs in.
 *
 * Before this, a new workspace opened on an empty board and a setup banner —
 * technically correct and completely useless. Its stack lists were empty (so
 * WorkspaceReadiness held scoring back), and the lead pool only reached it on
 * the next poll, which meant a customer's first impression of a lead engine
 * was a screen with no leads.
 *
 * So: seed the trade, then fill the board.
 *
 * Both steps are best-effort and neither is allowed to fail workspace
 * creation. A workspace that exists with no starter stacks is a small
 * annoyance its owner can fix in Settings; a failed creation leaves an
 * ownerless tenant, a half-sent invitation and nobody sure what happened.
 */
class WorkspaceBootstrapper
{
    /**
     * How much history a new workspace opens with.
     *
     * Age-bounded rather than count-bounded: a job older than this is dead
     * inventory nobody can win, and every lead handed over costs one scoring
     * call. Seven days matches the shipped max_posted_age_days, so a new
     * workspace starts with exactly the window the engine considers live.
     */
    public const BACKFILL_DAYS = 7;

    public function __construct(protected SettingsService $settings) {}

    /**
     * @param  string|null  $preset  a key from config('specializations.presets')
     */
    public function bootstrap(Tenant $tenant, ?string $preset = null): void
    {
        $this->applyPreset($tenant, $preset);

        // Queued, not inline. A backfill can hand over a few hundred leads
        // and dispatch a scoring job for each — that cannot happen inside the
        // HTTP request that is creating the workspace.
        BackfillWorkspaceFromPoolJob::dispatch($tenant->id, self::BACKFILL_DAYS);
    }

    /**
     * Write the preset's stack lists into the workspace's own settings.
     *
     * Never overwrites: if the lists already hold anything, somebody has
     * been here before and their choices win. That makes this safe to run
     * again on an existing workspace.
     */
    public function applyPreset(Tenant $tenant, ?string $preset): bool
    {
        if ($preset === null || $preset === '') {
            return false;
        }

        $config = config("specializations.presets.{$preset}");

        if (! is_array($config)) {
            return false;
        }

        return Tenancy::runAs($tenant, function () use ($tenant, $preset, $config) {
            $existing = $this->settings->stackLists();

            if ($existing['core'] !== [] || $existing['secondary'] !== []) {
                return false;
            }

            $this->settings->setMany([
                'core_stacks' => $config['core_stacks'] ?? [],
                'secondary_stacks' => $config['secondary_stacks'] ?? [],
                'excluded_stacks' => $config['excluded_stacks'] ?? [],
            ]);

            // The free-text label follows the preset so the platform console
            // and the workspace's own header agree with what it scores on —
            // but never over a label somebody typed deliberately, which is
            // always more specific than a preset name.
            if (($config['label'] ?? null) !== null && trim((string) $tenant->specialization) === '') {
                // TENANCY: the tenants table is never tenant-scoped — it is
                // the table the scope is derived from. Writing a workspace's
                // own label while bound to that same workspace.
                Tenancy::asPlatform(fn () => $tenant->update(['specialization' => $config['label']]));
            }

            ActivityLog::record('workspace_preset_applied', meta: [
                'preset' => $preset,
                'core_stacks' => $config['core_stacks'] ?? [],
            ]);

            return true;
        });
    }

    /**
     * The presets a creation form can offer.
     *
     * @return array<int, array{key: string, label: string, core_stacks: array<int, string>}>
     */
    public static function presets(): array
    {
        return collect((array) config('specializations.presets', []))
            ->map(fn (array $p, string $key) => [
                'key' => $key,
                'label' => $p['label'] ?? $key,
                'core_stacks' => $p['core_stacks'] ?? [],
            ])
            ->values()
            ->all();
    }
}
