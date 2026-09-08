<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Feedback>
 */
class FeedbackFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => null,
            'event_id' => null,
            'event_type_id' => null,
            'client_name' => $this->faker->name(),
            'event_date' => $this->faker->dateTimeBetween('-1 year', 'today')->format('Y-m-d'),
            'creativity' => $this->faker->numberBetween(1, 6),
            'excellence' => $this->faker->numberBetween(1, 6),
            'transcendence' => $this->faker->numberBetween(1, 6),
            'bespoke' => $this->faker->numberBetween(1, 6),
            'integrity' => $this->faker->numberBetween(1, 6),
            'exactitude' => $this->faker->numberBetween(1, 6),
            'intentionality' => $this->faker->numberBetween(1, 6),
            'genuine_connection' => $this->faker->numberBetween(1, 6),
            'communication_rating' => $this->faker->numberBetween(1, 6),
            'experience_comparison' => $this->faker->randomElement(['below_expectations', 'met_expectations', 'exceeded_expectations']),
            'likely_to_return' => $this->faker->boolean(),
            'would_recommend' => $this->faker->boolean(),
            'unmet_expectations' => $this->faker->optional()->sentence(),
            'stood_out' => $this->faker->optional()->sentence(),
            'status' => 'new',
        ];
    }

    /**
     * Attach the feedback to an existing client.
     */
    public function forClient(Client $client): static
    {
        return $this->state(fn () => [
            'client_id' => $client->id,
            'client_name' => trim($client->firstname . ' ' . $client->lastname),
        ]);
    }

    /**
     * Attach the feedback to an existing event.
     */
    public function forEvent(Event $event): static
    {
        return $this->state(fn () => [
            'event_id' => $event->id,
            'event_date' => $event->event_date?->format('Y-m-d'),
        ]);
    }

    /**
     * Attach the feedback to an existing event type.
     */
    public function forEventType(\App\Models\EventType $eventType): static
    {
        return $this->state(fn () => ['event_type_id' => $eventType->id]);
    }

    /**
     * Indicate that the feedback has been reviewed by an admin.
     */
    public function reviewed(): static
    {
        return $this->state(fn () => ['status' => 'reviewed']);
    }
}
