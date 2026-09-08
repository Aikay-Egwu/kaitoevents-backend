<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\EventType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eventDate = $this->faker->dateTimeBetween('now', '+6 months');
        $startTime = $this->faker->dateTimeBetween($eventDate->format('Y-m-d') . ' 08:00:00', $eventDate->format('Y-m-d') . ' 18:00:00');
        $endTime = (clone $startTime)->modify('+' . $this->faker->numberBetween(2, 8) . ' hours');

        return [
            'client_id' => Client::factory(),
            'event_type_id' => EventType::factory(),
            'event_name' => $this->faker->optional(0.6)->sentence(3),
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'number_of_guests' => $this->faker->numberBetween(20, 500),
            'budget' => (string) $this->faker->randomFloat(2, 1000, 50000),
            'special_instructions' => $this->faker->optional(0.7)->paragraph,
            'status' => $this->faker->randomElement(['inquiry', 'planned', 'confirmed', 'completed', 'cancelled']),
        ];
    }

    /**
     * Indicate that the event is confirmed.
     */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'confirmed',
        ]);
    }

    /**
     * Indicate that the event is in progress.
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'in_progress',
        ]);
    }

    /**
     * Indicate that the event is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }

    /**
     * Indicate that the event is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }

    /**
     * Indicate that the event is upcoming.
     */
    public function upcoming(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_date' => $this->faker->dateTimeBetween('now', '+6 months'),
            'status' => $this->faker->randomElement(['confirmed', 'pending']),
        ]);
    }

    /**
     * Indicate that the event is past.
     */
    public function past(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_date' => $this->faker->dateTimeBetween('-6 months', 'now'),
            'status' => $this->faker->randomElement(['completed', 'cancelled']),
        ]);
    }
}