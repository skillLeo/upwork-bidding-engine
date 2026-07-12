<?php

namespace Database\Seeders;

use App\Services\SettingsService;
use Illuminate\Database\Seeder;

/**
 * Seeds only the non-secret rule defaults from the product spec. API keys
 * and tokens are deliberately left unset — an admin fills those in from the
 * Settings screen, they are never faked or placeholder-seeded.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        app(SettingsService::class)->setMany([
            'claude_model' => 'claude-sonnet-4-6',
            'min_budget' => 150,
            'max_proposals' => 25,
            'score_cutoff' => 7,
            'stack_keywords' => ['Laravel', 'PHP', 'Next.js', 'React', 'Node', 'MERN', 'Flutter', 'Python'],
            'hourly_floor' => 8,
            'zero_history_budget_floor' => 100,
            'red_flag_words' => ['free test', 'unpaid sample', 'urgent no budget', 'revenue share only'],
            'followup_days' => 3,
        ]);
    }
}
