<?php

namespace App\Console\Commands;

use App\Services\SettingsService;
use Illuminate\Console\Command;

/**
 * Loads the versioned rule files in docs/ai-rules into the LIVE settings
 * the AI services read on every call. Settings stay the live copy the
 * operator edits in the browser; this command exists to push a repo-side
 * rules update (like the 2026 proposal addendum) into them after a
 * deploy — so it OVERWRITES any browser edits made since the files were
 * last synced. It prints sizes and asks nothing; run it deliberately.
 */
class AiSyncPromptsCommand extends Command
{
    protected $signature = 'ai:sync-prompts {--only= : scoring|skill|reference — sync just one}';

    protected $description = 'Load docs/ai-rules prompt files into the live scoring/proposal settings';

    public function handle(SettingsService $settings): int
    {
        $map = [
            'scoring' => ['scoring_system_prompt', base_path('docs/ai-rules/scoring-system-prompt.md')],
            // Split on purpose: `skill` is the ONLY proposal rules text
            // ever sent to a model; `reference` is operator reading that
            // must never enter a prompt (style contamination).
            'skill' => ['proposal_skill', base_path('docs/ai-rules/proposal-skill.md')],
            'reference' => ['proposal_reference', base_path('docs/ai-rules/proposal-reference.md')],
        ];

        $only = $this->option('only');

        if ($only !== null && ! isset($map[$only])) {
            $this->error('--only must be "scoring", "skill", or "reference".');

            return self::FAILURE;
        }

        foreach ($map as $name => [$key, $path]) {
            if ($only !== null && $only !== $name) {
                continue;
            }

            if (! is_file($path)) {
                $this->error("Missing {$path} — nothing synced for {$name}.");

                return self::FAILURE;
            }

            $content = trim((string) file_get_contents($path));
            $previous = mb_strlen((string) $settings->get($key));

            $settings->set($key, $content);

            $this->info(sprintf(
                '%s: synced %s chars into %s (was %s chars).',
                $name,
                number_format(mb_strlen($content)),
                $key,
                number_format($previous),
            ));
        }

        return self::SUCCESS;
    }
}
