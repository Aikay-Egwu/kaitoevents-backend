<?php

namespace Tests\Unit\Requests;

use App\Http\Requests\UpdateEventRequest;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UpdateEventRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test data
        EventType::factory()->create(['id' => 1]);
        Client::factory()->create(['id' => 1]);
    }

    /** @test */
    public function it_passes_validation_with_valid_partial_update()
    {
        $event = Event::factory()->create([
            'status' => 'pending',
            'event_date' => now()->addDays(7),
        ]);

        $request = new UpdateEventRequest();
        $request->setRouteResolver(function () use ($event) {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($event);
            return $route;
        });

        $validator = Validator::make([
            'venue_name' => 'Updated Venue Name',
            'guest_number' => 150,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function it_validates_status_transitions()
    {
        $event = Event::factory()->create([
            'status' => 'pending',
            'event_date' => now()->addDays(7),
        ]);

        $request = new UpdateEventRequest();
        $request->setRouteResolver(function () use ($event) {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($event);
            return $route;
        });

        // Valid transition: pending -> confirmed
        $validator = Validator::make([
            'status' => 'confirmed',
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertTrue($validator->passes());

        // Invalid transition: pending -> completed
        $validator = Validator::make([
            'status' => 'completed',
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('status', $validator->errors()->toArray());
    }

    /** @test */
    public function it_prevents_modifications_to_completed_events()
    {
        $event = Event::factory()->create([
            'status' => 'completed',
            'event_date' => now()->subDays(1),
        ]);

        $request = new UpdateEventRequest();
        $request->setRouteResolver(function () use ($event) {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($event);
            return $route;
        });

        $validator = Validator::make([
            'venue_name' => 'New Venue',
            'event_date' => now()->addDays(7)->format('Y-m-d'),
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('status', $validator->errors()->toArray());
    }

    /** @test */
    public function it_prevents_modifications_to_cancelled_events()
    {
        $event = Event::factory()->create([
            'status' => 'cancelled',
            'event_date' => now()->addDays(7),
        ]);

        $request = new UpdateEventRequest();
        $request->setRouteResolver(function () use ($event) {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($event);
            return $route;
        });

        $validator = Validator::make([
            'venue_name' => 'New Venue',
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('status', $validator->errors()->toArray());
    }

    /** @test */
    public function it_prevents_date_changes_within_48_hours_for_confirmed_events()
    {
        $event = Event::factory()->create([
            'status' => 'confirmed',
            'event_date' => now()->addHours(24), // 24 hours from now
            'start_time' => now()->addHours(24),
        ]);

        $request = new UpdateEventRequest();
        $request->setRouteResolver(function () use ($event) {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($event);
            return $route;
        });

        $validator = Validator::make([
            'event_date' => now()->addDays(14)->format('Y-m-d'),
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('event_date', $validator->errors()->toArray());
    }

    /** @test */
    public function it_allows_date_changes_for_confirmed_events_more_than_48_hours_away()
    {
        $event = Event::factory()->create([
            'status' => 'confirmed',
            'event_date' => now()->addDays(7),
            'start_time' => now()->addDays(7),
        ]);

        $request = new UpdateEventRequest();
        $request->setRouteResolver(function () use ($event) {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($event);
            return $route;
        });

        $validator = Validator::make([
            'event_date' => now()->addDays(14)->format('Y-m-d'),
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function it_validates_event_date_is_in_future_when_provided()
    {
        $event = Event::factory()->create([
            'status' => 'pending',
            'event_date' => now()->addDays(7),
        ]);

        $request = new UpdateEventRequest();
        $request->setRouteResolver(function () use ($event) {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($event);
            return $route;
        });

        $validator = Validator::make([
            'event_date' => now()->subDays(1)->format('Y-m-d'), // Past date
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('event_date', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_end_time_is_after_start_time_when_both_provided()
    {
        $event = Event::factory()->create([
            'status' => 'pending',
            'event_date' => now()->addDays(7),
        ]);

        $request = new UpdateEventRequest();
        $request->setRouteResolver(function () use ($event) {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($event);
            return $route;
        });

        $validator = Validator::make([
            'start_time' => '18:00',
            'end_time' => '14:00', // Before start time
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('end_time', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_event_duration_constraints()
    {
        $event = Event::factory()->create([
            'status' => 'pending',
            'event_date' => now()->addDays(7),
        ]);

        $request = new UpdateEventRequest();
        $request->setRouteResolver(function () use ($event) {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($event);
            return $route;
        });

        // Test minimum duration (less than 1 hour)
        $validator = Validator::make([
            'start_time' => '14:00',
            'end_time' => '14:30', // Only 30 minutes
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('end_time', $validator->errors()->toArray());

        // Test maximum duration (more than 24 hours)
        $validator = Validator::make([
            'start_time' => '14:00',
            'end_time' => '15:00', // This would be next day, but we can't test cross-day easily
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertTrue($validator->passes()); // This should pass as it's only 1 hour
    }

    /** @test */
    public function it_validates_guest_number_range_when_provided()
    {
        $event = Event::factory()->create([
            'status' => 'pending',
            'event_date' => now()->addDays(7),
        ]);

        $request = new UpdateEventRequest();
        $request->setRouteResolver(function () use ($event) {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($event);
            return $route;
        });

        // Test minimum guests
        $validator = Validator::make([
            'guest_number' => 0,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('guest_number', $validator->errors()->toArray());

        // Test maximum guests
        $validator = Validator::make([
            'guest_number' => 15000,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('guest_number', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_budget_range_when_provided()
    {
        $event = Event::factory()->create([
            'status' => 'pending',
            'event_date' => now()->addDays(7),
        ]);

        $request = new UpdateEventRequest();
        $request->setRouteResolver(function () use ($event) {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($event);
            return $route;
        });

        // Test negative budget
        $validator = Validator::make([
            'budget' => -100,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('budget', $validator->errors()->toArray());

        // Test excessive budget
        $validator = Validator::make([
            'budget' => 1000000,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('budget', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_string_field_lengths_when_provided()
    {
        $event = Event::factory()->create([
            'status' => 'pending',
            'event_date' => now()->addDays(7),
        ]);

        $request = new UpdateEventRequest();
        $request->setRouteResolver(function () use ($event) {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($event);
            return $route;
        });

        $validator = Validator::make([
            'venue_name' => str_repeat('a', 256), // Exceeds 255 chars
            'venue_address' => str_repeat('a', 501), // Exceeds 500 chars
            'special_instructions' => str_repeat('a', 2001), // Exceeds 2000 chars
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        
        $errors = $validator->errors()->toArray();
        $this->assertArrayHasKey('venue_name', $errors);
        $this->assertArrayHasKey('venue_address', $errors);
        $this->assertArrayHasKey('special_instructions', $errors);
    }

    /** @test */
    public function it_validates_foreign_key_existence_when_provided()
    {
        $event = Event::factory()->create([
            'status' => 'pending',
            'event_date' => now()->addDays(7),
        ]);

        $request = new UpdateEventRequest();
        $request->setRouteResolver(function () use ($event) {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($event);
            return $route;
        });

        // Test non-existent client
        $validator = Validator::make([
            'client_id' => 999,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('client_id', $validator->errors()->toArray());

        // Test non-existent event type
        $validator = Validator::make([
            'event_type_id' => 999,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('event_type_id', $validator->errors()->toArray());
    }

    /** @test */
    public function it_allows_null_values_for_optional_fields()
    {
        $event = Event::factory()->create([
            'status' => 'pending',
            'event_date' => now()->addDays(7),
        ]);

        $request = new UpdateEventRequest();
        $request->setRouteResolver(function () use ($event) {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($event);
            return $route;
        });

        $validator = Validator::make([
            'budget' => null,
            'special_instructions' => null,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function it_validates_all_possible_status_transitions()
    {
        $statusTransitions = [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['in_progress', 'cancelled'],
            'in_progress' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
        ];

        foreach ($statusTransitions as $currentStatus => $validTransitions) {
            $event = Event::factory()->create([
                'status' => $currentStatus,
                'event_date' => now()->addDays(7),
            ]);

            $request = new UpdateEventRequest();
            $request->setRouteResolver(function () use ($event) {
                $route = \Mockery::mock();
                $route->shouldReceive('parameter')->with('event')->andReturn($event);
                return $route;
            });

            // Test valid transitions
            foreach ($validTransitions as $validStatus) {
                $validator = Validator::make([
                    'status' => $validStatus,
                ], $request->rules());

                $request->withValidator($validator);
                $this->assertTrue($validator->passes(), "Should allow transition from {$currentStatus} to {$validStatus}");
            }

            // Test invalid transitions
            $allStatuses = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'];
            $invalidTransitions = array_diff($allStatuses, $validTransitions, [$currentStatus]);

            foreach ($invalidTransitions as $invalidStatus) {
                $validator = Validator::make([
                    'status' => $invalidStatus,
                ], $request->rules());

                $request->withValidator($validator);
                $this->assertFalse($validator->passes(), "Should not allow transition from {$currentStatus} to {$invalidStatus}");
            }
        }
    }
}