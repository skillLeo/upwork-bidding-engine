<?php

namespace Database\Factories;

use App\Enums\ClientStage;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->boolean(60) ? fake()->company() : fake()->name(),
            'lead_id' => null,
            'budget_discussed' => fake()->randomElement(['$1,500 fixed', '$25/hr', '$3,000 fixed', '$40/hr', null]),
            'agreed_scope' => fake()->boolean(60) ? fake()->paragraph() : null,
            'stage' => ClientStage::New,
            'notes' => fake()->boolean(70) ? fake()->paragraph() : null,
        ];
    }

    public function stage(ClientStage $stage): static
    {
        return $this->state(fn (array $attributes) => ['stage' => $stage]);
    }
}
