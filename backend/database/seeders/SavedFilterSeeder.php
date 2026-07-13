<?php

namespace Database\Seeders;

use App\Models\SavedFilter;
use Illuminate\Database\Seeder;

/**
 * Not part of DatabaseSeeder's default chain - run once manually
 * (php artisan db:seed --class=SavedFilterSeeder) when you want these
 * starting points. Safe to re-run: upserts by name instead of duplicating.
 */
class SavedFilterSeeder extends Seeder
{
    public function run(): void
    {
        $filters = [
            [
                'name' => 'Web + Backend',
                'is_default' => true,
                'is_pinned' => true,
                'criteria' => [
                    'include_keywords' => [
                        'Laravel', 'PHP', 'Python', 'Django', 'FastAPI', 'Odoo',
                        'MySQL', 'PostgreSQL', 'Vue', 'Node', 'Express',
                    ],
                    'exclude_keywords' => ['WordPress', 'Wix', 'Shopify'],
                ],
            ],
            [
                'name' => 'JS / MERN / Next',
                'is_default' => false,
                'is_pinned' => true,
                'criteria' => [
                    'include_keywords' => [
                        'React', 'Next.js', 'Node', 'MERN', 'MongoDB', 'TypeScript', 'Vue',
                    ],
                    'exclude_keywords' => ['WordPress', 'video', 'logo'],
                ],
            ],
            [
                'name' => 'Mobile',
                'is_default' => false,
                'is_pinned' => true,
                'criteria' => [
                    'include_keywords' => ['Flutter', 'React Native', 'Dart', 'Firebase'],
                    'exclude_keywords' => ['game', 'Unity', 'no code'],
                ],
            ],
            [
                'name' => 'AI / ML / Vibe Coding',
                'is_default' => false,
                'is_pinned' => true,
                'criteria' => [
                    'include_keywords' => [
                        'AI', 'ML', 'Machine Learning', 'LLM', 'Claude', 'GPT',
                        'OpenAI', 'Vibe Coding', 'Agent', 'Automation',
                    ],
                    'exclude_keywords' => ['prompt writer', 'data entry'],
                ],
            ],
        ];

        foreach ($filters as $filter) {
            SavedFilter::query()->updateOrCreate(['name' => $filter['name']], $filter);
        }
    }
}
