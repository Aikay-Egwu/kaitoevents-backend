<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\JobGroup;
use App\Models\JobTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobTask>
 */
final class JobTaskFactory extends Factory
{
    protected $model = JobTask::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'event_id'           => Event::factory(),
            'event_job_group_id' => JobGroup::factory(),
            'title'              => $this->faker->sentence(6),
            'instructions'       => $this->faker->optional()->paragraph(),
            'status'             => $this->faker->randomElement(['pending', 'in_progress', 'completed', 'blocked']),
            'location'           => $this->faker->randomElement(['on_site', 'in_house']),
            'duration_minutes'   => $this->faker->optional()->numberBetween(15, 240),
        ];
    }
}
