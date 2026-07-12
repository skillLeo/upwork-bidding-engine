<?php

namespace Database\Factories;

use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true).' opener',
            'body' => fake()->paragraphs(2, true),
            'style' => fake()->randomElement(['concise', 'detailed', 'technical', null]),
        ];
    }
}
