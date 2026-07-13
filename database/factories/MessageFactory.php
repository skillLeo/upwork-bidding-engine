<?php

namespace Database\Factories;

use App\Enums\MessageDirection;
use App\Models\Client;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'direction' => fake()->randomElement([MessageDirection::In, MessageDirection::Out]),
            'text' => fake()->paragraph(2, true),
            'drafted_reply' => null,
            'needs_hassam' => false,
            'sent_at' => fake()->dateTimeBetween('-10 days', 'now'),
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => MessageDirection::In,
        ]);
    }

    public function outbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => MessageDirection::Out,
        ]);
    }

    public function needsHassam(): static
    {
        return $this->state(fn (array $attributes) => [
            'needs_hassam' => true,
            'drafted_reply' => null,
        ]);
    }
}
