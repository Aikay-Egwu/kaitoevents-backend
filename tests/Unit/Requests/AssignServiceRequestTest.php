<?php

namespace Tests\Unit\Requests;

use App\Http\Requests\AssignServiceRequest;
use App\Models\Event;
use App\Models\Service;
use App\Models\Client;
use App\Models\EventType;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class AssignServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    protected $event;
    protected $service;
    protected $category;

    protected function setUp(): void
    {
        parent::setUp();
        
        $client = Client::factory()->create();
        $eventType = EventType::factory()->create();
        $this->category = ServiceCategory::factory()->create();
        
        $this->event = Event::factory()->create([
            'client_id' => $client->id,
            'event_type_id' => $eventType->id,
            'event_date' => now()->addDays(30),
            'guest_number' => 100,
            'budget' => 5000.00,
        ]);

        $this->service = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'price' => 500.00,
            'is_active' => true,
            'max_capacity' => 200,
        ]);
    }

    /** @test */
    public function it_passes_validation_with_valid_data()
    {
        $request = new AssignServiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            return $route;
        });

        $validator = Validator::make([
            'service_id' => $this->service->id,
            'quantity' => 2,
            'custom_price' => 450.00,
            'notes' => 'Special setup required',
            'scheduled_date' => $this->event->event_date->format('Y-m-d'),
            'scheduled_time' => '14:00',
            'duration_hours' => 4.0,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function it_requires_service_id()
    {
        $request = new AssignServiceRequest();
        $validator = Validator::make([], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('service_id', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_service_exists_and_is_active()
    {
        $request = new AssignServiceRequest();
        
        // Test non-existent service
        $validator = Validator::make([
            'service_id' => 999,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('service_id', $validator->errors()->toArray());

        // Test inactive service
        $inactiveService = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'is_active' => false,
        ]);

        $validator = Validator::make([
            'service_id' => $inactiveService->id,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('service_id', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_quantity_range()
    {
        $request = new AssignServiceRequest();
        
        // Test minimum quantity
        $validator = Validator::make([
            'service_id' => $this->service->id,
            'quantity' => 0,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('quantity', $validator->errors()->toArray());

        // Test maximum quantity
        $validator = Validator::make([
            'service_id' => $this->service->id,
            'quantity' => 101,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('quantity', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_custom_price_range()
    {
        $request = new AssignServiceRequest();
        
        // Test negative price
        $validator = Validator::make([
            'service_id' => $this->service->id,
            'custom_price' => -100,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('custom_price', $validator->errors()->toArray());

        // Test excessive price
        $validator = Validator::make([
            'service_id' => $this->service->id,
            'custom_price' => 100000,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('custom_price', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_duration_constraints()
    {
        $request = new AssignServiceRequest();
        
        // Test minimum duration
        $validator = Validator::make([
            'service_id' => $this->service->id,
            'duration_hours' => 0.25, // Less than 0.5
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('duration_hours', $validator->errors()->toArray());

        // Test maximum duration
        $validator = Validator::make([
            'service_id' => $this->service->id,
            'duration_hours' => 25, // More than 24
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('duration_hours', $validator->errors()->toArray());
    }

    /** @test */
    public function it_prevents_duplicate_service_assignment()
    {
        // Assign service to event first
        $this->event->services()->attach($this->service->id);

        $request = new AssignServiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            return $route;
        });

        $validator = Validator::make([
            'service_id' => $this->service->id,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('service_id', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_scheduled_date_within_event_timeframe()
    {
        $request = new AssignServiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            return $route;
        });

        // Test date too far from event
        $validator = Validator::make([
            'service_id' => $this->service->id,
            'scheduled_date' => $this->event->event_date->addDays(5)->format('Y-m-d'),
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('scheduled_date', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_budget_constraints()
    {
        $expensiveService = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'price' => 6000.00, // More than event budget
            'is_active' => true,
        ]);

        $request = new AssignServiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            return $route;
        });

        $validator = Validator::make([
            'service_id' => $expensiveService->id,
            'quantity' => 1,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('custom_price', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_service_capacity_constraints()
    {
        $limitedService = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'max_capacity' => 50, // Less than event guest count
            'is_active' => true,
        ]);

        $request = new AssignServiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            return $route;
        });

        $validator = Validator::make([
            'service_id' => $limitedService->id,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('service_id', $validator->errors()->toArray());
    }

    /** @test */
    public function it_sets_default_quantity()
    {
        $request = new AssignServiceRequest();
        $request->replace(['service_id' => $this->service->id]);
        $request->prepareForValidation();

        $this->assertEquals(1, $request->get('quantity'));
    }

    /** @test */
    public function it_formats_custom_price()
    {
        $request = new AssignServiceRequest();
        $request->replace([
            'service_id' => $this->service->id,
            'custom_price' => 123.456,
        ]);
        $request->prepareForValidation();

        $this->assertEquals(123.46, $request->get('custom_price'));
    }

    /** @test */
    public function it_formats_duration_hours()
    {
        $request = new AssignServiceRequest();
        $request->replace([
            'service_id' => $this->service->id,
            'duration_hours' => 4.567,
        ]);
        $request->prepareForValidation();

        $this->assertEquals(4.57, $request->get('duration_hours'));
    }

    /** @test */
    public function it_calculates_pricing_correctly()
    {
        $request = new AssignServiceRequest();
        $request->replace([
            'service_id' => $this->service->id,
            'quantity' => 2,
            'custom_price' => 450.00,
        ]);

        $validatedData = $request->getValidatedDataWithPricing();

        $this->assertEquals(450.00, $validatedData['unit_price']);
        $this->assertEquals(900.00, $validatedData['total_price']);
        $this->assertEquals(500.00, $validatedData['original_price']);
        $this->assertTrue($validatedData['price_override']);
    }

    /** @test */
    public function it_uses_service_default_price_when_no_custom_price()
    {
        $request = new AssignServiceRequest();
        $request->replace([
            'service_id' => $this->service->id,
            'quantity' => 3,
        ]);

        $validatedData = $request->getValidatedDataWithPricing();

        $this->assertEquals(500.00, $validatedData['unit_price']);
        $this->assertEquals(1500.00, $validatedData['total_price']);
        $this->assertEquals(500.00, $validatedData['original_price']);
        $this->assertFalse($validatedData['price_override']);
    }

    /** @test */
    public function it_generates_assignment_summary()
    {
        $request = new AssignServiceRequest();
        $request->replace([
            'service_id' => $this->service->id,
            'quantity' => 2,
            'custom_price' => 450.00,
            'notes' => 'Special requirements',
            'scheduled_date' => $this->event->event_date->format('Y-m-d'),
            'scheduled_time' => '14:00',
            'duration_hours' => 4.0,
        ]);

        $summary = $request->getAssignmentSummary();

        $this->assertArrayHasKey('service', $summary);
        $this->assertArrayHasKey('assignment', $summary);
        $this->assertEquals($this->service->id, $summary['service']['id']);
        $this->assertEquals(2, $summary['assignment']['quantity']);
        $this->assertEquals('450.00', $summary['assignment']['unit_price']);
        $this->assertEquals('900.00', $summary['assignment']['total_price']);
        $this->assertTrue($summary['assignment']['price_override']);
    }

    /** @test */
    public function it_validates_time_format()
    {
        $request = new AssignServiceRequest();
        
        $validator = Validator::make([
            'service_id' => $this->service->id,
            'scheduled_time' => '2:00 PM', // Invalid format
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('scheduled_time', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_scheduled_date_not_in_past()
    {
        $request = new AssignServiceRequest();
        
        $validator = Validator::make([
            'service_id' => $this->service->id,
            'scheduled_date' => now()->subDays(1)->format('Y-m-d'),
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('scheduled_date', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_notes_length()
    {
        $request = new AssignServiceRequest();
        
        $validator = Validator::make([
            'service_id' => $this->service->id,
            'notes' => str_repeat('a', 1001), // Exceeds 1000 chars
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('notes', $validator->errors()->toArray());
    }

    /** @test */
    public function it_allows_null_optional_fields()
    {
        $request = new AssignServiceRequest();
        
        $validator = Validator::make([
            'service_id' => $this->service->id,
            'custom_price' => null,
            'notes' => null,
            'scheduled_date' => null,
            'scheduled_time' => null,
            'duration_hours' => null,
        ], $request->rules());

        $this->assertTrue($validator->passes());
    }
}