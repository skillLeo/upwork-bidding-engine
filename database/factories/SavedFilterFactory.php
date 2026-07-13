<?php

namespace Database\Factories;

use App\Models\SavedFilter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedFilter>
 */
class SavedFilterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'is_default' => false,
            'is_pinned' => false,
            'criteria' => [
                'include_keywords' => [],
                'exclude_keywords' => [],
            ],
        ];
    }
}
