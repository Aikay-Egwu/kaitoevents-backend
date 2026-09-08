<?php

namespace Tests\Unit\Requests;

use App\Http\Requests\RemoveServiceRequest;
use App\Models\Event;
use App\Models\Service;
use App\Models\Client;
use App\Models\EventType;
use App\Models\ServiceCategory;
use App\Models\EventInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class RemoveServiceRequestTest extends TestCase
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
            'status' => 'confirmed',
        ]);

        $this->service = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'price' => 500.00,
            'is_active' => true,
        ]);

        // Assign service to event
        $this->event->services()->attach($this->service->id, [
            'quantity' => 2,
            'price' => 500.00,
        ]);
    }

    /** @test */
    public function it_passes_validation_with_valid_data()
    {
        $request = new RemoveServiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            $route->shouldReceive('parameter')->with('service')->andReturn($this->service);
            return $route;
        });

        $validator = Validator::make([
            'reason' => 'Client requested change',
            'refund_amount' => 500.00,
            'force_remove' => false,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function it_validates_reason_length()
    {
        $request = new RemoveServiceRequest();
        
        $validator = Validator::make([
            'reason' => str_repeat('a', 501), // Exceeds 500 chars
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('reason', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_refund_amount_range()
    {
        $request = new RemoveServiceRequest();
        
        // Test negative refund amount
        $validator = Validator::make([
            'refund_amount' => -100,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('refund_amount', $validator->errors()->toArray());

        // Test excessive refund amount
        $validator = Validator::make([
            'refund_amount' => 100000,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('refund_amount', $validator->errors()->toArray());
    }

    /** @test */
    public function it_prevents_removal_from_completed_events_without_force()
    {
        $this->event->update(['status' => 'completed']);

        $request = new RemoveServiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            $route->shouldReceive('parameter')->with('service')->andReturn($this->service);
            return $route;
        });

        $validator = Validator::make([
            'force_remove' => false,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('force_remove', $validator->errors()->toArray());
    }

    /** @test */
    public function it_allows_removal_from_completed_events_with_force()
    {
        $this->event->update(['status' => 'completed']);

        $request = new RemoveServiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            $route->shouldReceive('parameter')->with('service')->andReturn($this->service);
            return $route;
        });

        $validator = Validator::make([
            'force_remove' => true,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function it_prevents_removal_from_cancelled_events_without_force()
    {
        $this->event->update(['status' => 'cancelled']);

        $request = new RemoveServiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            $route->shouldReceive('parameter')->with('service')->andReturn($this->service);
            return $route;
        });

        $validator = Validator::make([
            'force_remove' => false,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('force_remove', $validator->errors()->toArray());
    }

    /** @test */
    public function it_prevents_removal_within_24_hours_without_force()
    {
        $this->event->update([
            'event_date' => now()->addHours(12),
            'start_time' => now()->addHours(12),
        ]);

        $request = new RemoveServiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            $route->shouldReceive('parameter')->with('service')->andReturn($this->service);
            return $route;
        });

        $validator = Validator::make([
            'force_remove' => false,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('force_remove', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_refund_amount_does_not_exceed_service_cost()
    {
        $request = new RemoveServiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            $route->shouldReceive('parameter')->with('service')->andReturn($this->service);
            return $route;
        });

        $validator = Validator::make([
            'refund_amount' => 1500.00, // More than service cost (500 * 2 = 1000)
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('refund_amount', $validator->errors()->toArray());
    }

    /** @test */
    public function it_prevents_removal_from_paid_invoice_events_without_force()
    {
        EventInvoice::factory()->create([
            'event_id' => $this->event->id,
            'payment_status' => 'paid',
        ]);

        $request = new RemoveServiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            $route->shouldReceive('parameter')->with('service')->andReturn($this->service);
            return $route;
        });

        $validator = Validator::make([
            'force_remove' => false,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('force_remove', $validator->errors()->toArray());
    }

    /** @test */
    public function it_fails_when_service_not_assigned_to_event()
    {
        $unassignedService = Service::factory()->create([
            'service_category_id' => $this->category->id,
        ]);

        $request = new RemoveServiceRequest();
        $request->setRouteResolver(function () use ($unassignedService) {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            $route->shouldReceive('parameter')->with('service')->andReturn($unassignedService);
            return $route;
        });

        $validator = Validator::make([], $request->rules());

        $request->withValidator($validator);
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('service_id', $validator->errors()->toArray());
    }

    /** @test */
    public function it_generates_removal_summary()
    {
        $request = new RemoveServiceRequest();
        $request->replace([
            'reason' => 'Client requested change',
            'refund_amount' => 500.00,
            'force_remove' => false,
        ]);

        $summary = $request->getRemovalSummary($this->event, $this->service);

        $this->assertArrayHasKey('service', $summary);
        $this->assertArrayHasKey('assignment', $summary);
        $this->assertArrayHasKey('removal', $summary);
        $this->assertArrayHasKey('impact', $summary);

        $this->assertEquals($this->service->id, $summary['service']['id']);
        $this->assertEquals(2, $summary['assignment']['quantity']);
        $this->assertEquals('1,000.00', $summary['assignment']['total_cost']);
        $this->assertEquals('Client requested change', $summary['removal']['reason']);
        $this->assertEquals('500.00', $summary['removal']['refund_amount']);
        $this->assertEquals('1,000.00', $summary['impact']['cost_reduction']);
    }

    /** @test */
    public function it_allows_null_optional_fields()
    {
        $request = new RemoveServiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            $route->shouldReceive('parameter')->with('service')->andReturn($this->service);
            return $route;
        });

        $validator = Validator::make([
            'reason' => null,
            'refund_amount' => null,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function it_validates_force_remove_as_boolean()
    {
        $request = new RemoveServiceRequest();
        
        $validator = Validator::make([
            'force_remove' => 'not_boolean',
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('force_remove', $validator->errors()->toArray());
    }

    /** @test */
    public function it_provides_removal_warnings()
    {
        // Create event within 48 hours
        $this->event->update([
            'event_date' => now()->addHours(24),
            'start_time' => now()->addHours(24),
            'status' => 'confirmed',
        ]);

        // Create invoice
        EventInvoice::factory()->create([
            'event_id' => $this->event->id,
            'payment_status' => 'pending',
        ]);

        $request = new RemoveServiceRequest();
        $summary = $request->getRemovalSummary($this->event, $this->service);

        $warnings = $summary['impact']['warnings'];
        $this->assertNotEmpty($warnings);
        $this->assertContains('Event is within 48 hours - removal may affect event setup.', $warnings);
        $this->assertContains('Event has an existing invoice - removal may require invoice adjustment.', $warnings);
    }

    /** @test */
    public function it_handles_missing_route_parameters_gracefully()
    {
        $request = new RemoveServiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn(null);
            $route->shouldReceive('parameter')->with('service')->andReturn(null);
            return $route;
        });

        $validator = Validator::make([], $request->rules());

        // Should not throw exception when route parameters are missing
        $request->withValidator($validator);
        $this->assertTrue($validator->passes()); // Basic validation should pass
    }

    /** @test */
    public function it_returns_empty_summary_for_unassigned_service()
    {
        $unassignedService = Service::factory()->create([
            'service_category_id' => $this->category->id,
        ]);

        $request = new RemoveServiceRequest();
        $summary = $request->getRemovalSummary($this->event, $unassignedService);

        $this->assertEmpty($summary);
    }
}