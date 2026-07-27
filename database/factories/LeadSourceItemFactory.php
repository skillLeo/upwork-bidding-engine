<?php

namespace Database\Factories;

use App\Models\LeadSourceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadSourceItem>
 */
class LeadSourceItemFactory extends Factory
{
    protected $model = LeadSourceItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => 'pool_'.fake()->unique()->numerify('##########'),
            'source' => 'vollna',
            'title' => fake()->sentence(5),
            'full_brief' => fake()->paragraph(3, true),
            'skills' => fake()->randomElements(['PHP', 'Laravel', 'Vue', 'React', 'Figma', 'Unity'], 3),
            'url' => fake()->url(),
            'budget' => '$'.fake()->numberBetween(200, 5000).' fixed',
            'client_country' => fake()->country(),
            'payment_verified' => true,
            'proposal_count' => fake()->numberBetween(0, 20),
            'posted_at' => now()->subHours(fake()->numberBetween(1, 48)),
        ];
    }
}
