<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\JobGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobGroup>
 */
final class JobGroupFactory extends Factory
{
    protected $model = JobGroup::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'event_id'          => Event::factory(),
            'name'              => $this->faker->words(3, true),
            'team_lead_user_id' => User::factory(),
            'description'       => $this->faker->optional()->paragraph(),
        ];
    }
}
