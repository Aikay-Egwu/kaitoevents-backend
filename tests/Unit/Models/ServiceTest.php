<?php

namespace Tests\Unit\Models;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $service;
    protected $category;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->category = ServiceCategory::factory()->create();
        $this->service = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'price' => 500.00,
            'duration_hours' => 4.0,
            'is_active' => true,
            'max_capacity' => 200,
            'min_capacity' => 10,
        ]);
    }

    /** @test */
    public function it_has_correct_fillable_attributes()
    {
        $fillable = [
            'service_name', 'service_description', 'price', 'duration_hours', 'is_active',
            'service_category_id', 'max_capacity', 'min_capacity', 'requires_advance_notice',
            'advance_notice_hours', 'setup_time_hours', 'cleanup_time_hours', 'equipment_required',
            'staff_required', 'location_restrictions', 'seasonal_availability',
            'discount_eligible', 'service_image', 'service_tags'
        ];

        $service = new Service();
        $this->assertEquals($fillable, $service->getFillable());
    }

    /** @test */
    public function it_casts_attributes_correctly()
    {
        $service = Service::factory()->create([
            'price' => 123.456,
            'duration_hours' => 4.567,
            
            'is_active' => 1,
            'requires_advance_notice' => 1,
            'discount_eligible' => 0,
            'equipment_required' => ['tables', 'chairs'],
            'service_tags' => ['wedding', 'corporate'],
        ]);

        $this->assertEquals('123.46', $service->price);
        $this->assertEquals('4.57', $service->duration_hours);
        $this->assertEquals('0.0875', $service->tax_rate);
        $this->assertTrue($service->is_active);
        $this->assertTrue($service->requires_advance_notice);
        $this->assertFalse($service->discount_eligible);
        $this->assertIsArray($service->equipment_required);
        $this->assertIsArray($service->service_tags);
    }

    /** @test */
    public function it_belongs_to_category()
    {
        $this->assertInstanceOf(ServiceCategory::class, $this->service->category);
        $this->assertEquals($this->category->id, $this->service->category->id);
    }

    /** @test */
    public function it_has_many_events_through_pivot()
    {
        $client = Client::factory()->create();
        $eventType = EventType::factory()->create();
        $event = Event::factory()->create([
            'client_id' => $client->id,
            'event_type_id' => $eventType->id,
        ]);
        
        $this->service->events()->attach($event->id, [
            'quantity' => 2,
            'price' => 450.00,
            'notes' => 'Special setup',
        ]);

        $this->assertCount(1, $this->service->events);
        $this->assertEquals($event->id, $this->service->events->first()->id);
        $this->assertEquals(2, $this->service->events->first()->pivot->quantity);
        $this->assertEquals(450.00, $this->service->events->first()->pivot->price);
    }

    /** @test */
    public function it_scopes_active_services()
    {
        Service::factory()->create([
            'service_category_id' => $this->category->id,
            'is_active' => false,
        ]);

        $activeServices = Service::active()->get();
        
        $this->assertCount(1, $activeServices);
        $this->assertEquals($this->service->id, $activeServices->first()->id);
    }

    /** @test */
    public function it_scopes_by_category()
    {
        $otherCategory = ServiceCategory::factory()->create();
        Service::factory()->create(['service_category_id' => $otherCategory->id]);

        $categoryServices = Service::byCategory($this->category->id)->get();
        
        $this->assertCount(1, $categoryServices);
        $this->assertEquals($this->service->id, $categoryServices->first()->id);
    }

    /** @test */
    public function it_scopes_by_price_range()
    {
        Service::factory()->create([
            'service_category_id' => $this->category->id,
            'price' => 100.00,
        ]);
        Service::factory()->create([
            'service_category_id' => $this->category->id,
            'price' => 1000.00,
        ]);

        $priceRangeServices = Service::byPriceRange(400.00, 600.00)->get();
        
        $this->assertCount(1, $priceRangeServices);
        $this->assertEquals($this->service->id, $priceRangeServices->first()->id);
    }

    /** @test */
    public function it_scopes_by_duration()
    {
        Service::factory()->create([
            'service_category_id' => $this->category->id,
            'duration_hours' => 2.0,
        ]);
        Service::factory()->create([
            'service_category_id' => $this->category->id,
            'duration_hours' => 8.0,
        ]);

        $durationServices = Service::byDuration(3.0, 5.0)->get();
        
        $this->assertCount(1, $durationServices);
        $this->assertEquals($this->service->id, $durationServices->first()->id);
    }

    /** @test */
    public function it_scopes_available_for_capacity()
    {
        Service::factory()->create([
            'service_category_id' => $this->category->id,
            'max_capacity' => 50, // Too small for 100 guests
        ]);
        Service::factory()->create([
            'service_category_id' => $this->category->id,
            'min_capacity' => 150, // Too large for 100 guests
        ]);

        $capacityServices = Service::availableForCapacity(100)->get();
        
        $this->assertCount(1, $capacityServices);
        $this->assertEquals($this->service->id, $capacityServices->first()->id);
    }

    /** @test */
    public function it_searches_services()
    {
        $this->service->update([
            'service_name' => 'Wedding Photography',
            'service_description' => 'Professional wedding photos',
            'service_tags' => ['photography', 'wedding'],
        ]);

        Service::factory()->create([
            'service_category_id' => $this->category->id,
            'service_name' => 'Catering Service',
            'service_description' => 'Food and beverage service',
        ]);

        $searchResults = Service::search('wedding')->get();
        
        $this->assertCount(1, $searchResults);
        $this->assertEquals($this->service->id, $searchResults->first()->id);
    }

    /** @test */
    public function it_checks_if_service_is_active()
    {
        $this->assertTrue($this->service->isActive());

        $this->service->update(['is_active' => false]);
        $this->assertFalse($this->service->isActive());
    }

    /** @test */
    public function it_checks_availability_for_event()
    {
        $client = Client::factory()->create();
        $eventType = EventType::factory()->create();
        $event = Event::factory()->create([
            'client_id' => $client->id,
            'event_type_id' => $eventType->id,
            'guest_number' => 100,
        ]);

        $this->assertTrue($this->service->isAvailableForEvent($event));

        // Test inactive service
        $this->service->update(['is_active' => false]);
        $this->assertFalse($this->service->isAvailableForEvent($event));

        // Test capacity constraints
        $this->service->update(['is_active' => true, 'max_capacity' => 50]);
        $this->assertFalse($this->service->isAvailableForEvent($event));

        $this->service->update(['max_capacity' => 200, 'min_capacity' => 150]);
        $this->assertFalse($this->service->isAvailableForEvent($event));
    }

    /** @test */
    public function it_calculates_price_for_event()
    {
        $client = Client::factory()->create();
        $eventType = EventType::factory()->create();
        $event = Event::factory()->create([
            'client_id' => $client->id,
            'event_type_id' => $eventType->id,
            'guest_number' => 100,
        ]);

        // Test default pricing
        $price = $this->service->calculatePriceForEvent($event, 2);
        $this->assertEquals(1000.00, $price); // 500 * 2

        // Test custom pricing
        $customPrice = $this->service->calculatePriceForEvent($event, 2, 450.00);
        $this->assertEquals(900.00, $customPrice); // 450 * 2

        // Test capacity-based pricing

        $largeEvent = Event::factory()->create([
            'client_id' => $client->id,
            'event_type_id' => $eventType->id,
            'guest_number' => 150,
        ]);
        
        $largePricePrice = $this->service->calculatePriceForEvent($largeEvent, 1);
        $this->assertEquals(600.00, $largePricePrice); // 500 * 1.2
    }

    /** @test */
    public function it_calculates_total_duration_with_setup()
    {
        $this->service->update([
            'duration_hours' => 4.0,
            'setup_time_hours' => 1.0,
            'cleanup_time_hours' => 0.5,
        ]);

        $totalDuration = $this->service->getTotalDurationWithSetup();
        $this->assertEquals(5.5, $totalDuration);
    }

    /** @test */
    public function it_gets_advance_notice_required()
    {
        $this->service->update([
            'requires_advance_notice' => false,
        ]);
        $this->assertEquals(0, $this->service->getAdvanceNoticeRequired());

        $this->service->update([
            'requires_advance_notice' => true,
            'advance_notice_hours' => 48,
        ]);
        $this->assertEquals(48, $this->service->getAdvanceNoticeRequired());

        $this->service->update([
            'requires_advance_notice' => true,
            'advance_notice_hours' => null,
        ]);
        $this->assertEquals(24, $this->service->getAdvanceNoticeRequired()); // Default
    }

    /** @test */
    public function it_checks_service_compatibility()
    {
        $compatibleService = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'equipment_required' => ['microphones', 'speakers'],
        ]);

        $conflictingService = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'equipment_required' => ['tables', 'chairs'], // Same as main service
        ]);

        $this->service->update(['equipment_required' => ['tables', 'chairs']]);

        $this->assertTrue($this->service->isCompatibleWith($compatibleService));
        $this->assertFalse($this->service->isCompatibleWith($conflictingService));
    }

    /** @test */
    public function it_checks_scheduling_availability()
    {
        $dateTime = new \DateTime('2024-12-25 14:00:00');
        $this->assertTrue($this->service->canBeScheduledAt($dateTime, 4.0));

        // Test outside business hours
        $lateDateTime = new \DateTime('2024-12-25 23:00:00');
        $this->assertFalse($this->service->canBeScheduledAt($lateDateTime, 4.0));

        $earlyDateTime = new \DateTime('2024-12-25 06:00:00');
        $this->assertFalse($this->service->canBeScheduledAt($earlyDateTime, 4.0));
    }

    /** @test */
    public function it_calculates_discounted_price()
    {
        $this->service->update(['discount_eligible' => true]);
        
        $discountedPrice = $this->service->getDiscountedPrice(20); // 20% discount
        $this->assertEquals(400.00, $discountedPrice);

        // Test non-discount eligible service
        $this->service->update(['discount_eligible' => false]);
        $nonDiscountedPrice = $this->service->getDiscountedPrice(20);
        $this->assertEquals(500.00, $nonDiscountedPrice); // Original price
    }

    /** @test */
    public function it_calculates_tax_amount()
    {
        $this->service->update(['tax_rate' => 0.08]);
        
        $taxAmount = $this->service->getTaxAmount();
        $this->assertEquals(40.00, $taxAmount); // 500 * 0.08

        $customTaxAmount = $this->service->getTaxAmount(1000.00);
        $this->assertEquals(80.00, $customTaxAmount); // 1000 * 0.08
    }

    /** @test */
    public function it_calculates_total_price_with_tax()
    {
        $this->service->update(['tax_rate' => 0.08]);
        
        $totalPrice = $this->service->getTotalPriceWithTax();
        $this->assertEquals(540.00, $totalPrice); // 500 + (500 * 0.08)

        $customTotalPrice = $this->service->getTotalPriceWithTax(1000.00);
        $this->assertEquals(1080.00, $customTotalPrice); // 1000 + (1000 * 0.08)
    }

    /** @test */
    public function it_gets_estimated_revenue()
    {
        $client = Client::factory()->create();
        $eventType = EventType::factory()->create();
        
        $event1 = Event::factory()->create([
            'client_id' => $client->id,
            'event_type_id' => $eventType->id,
        ]);
        $event2 = Event::factory()->create([
            'client_id' => $client->id,
            'event_type_id' => $eventType->id,
        ]);

        $this->service->events()->attach($event1->id, ['price' => 500.00]);
        $this->service->events()->attach($event2->id, ['price' => 600.00]);

        $revenue = $this->service->getEstimatedRevenue();
        $this->assertEquals(1100.00, $revenue);
    }

    /** @test */
    public function it_gets_booking_count()
    {
        $client = Client::factory()->create();
        $eventType = EventType::factory()->create();
        
        Event::factory()->count(3)->create([
            'client_id' => $client->id,
            'event_type_id' => $eventType->id,
        ])->each(function ($event) {
            $this->service->events()->attach($event->id);
        });

        $bookingCount = $this->service->getBookingCount();
        $this->assertEquals(3, $bookingCount);
    }

    /** @test */
    public function it_determines_if_service_is_popular()
    {
        $this->assertFalse($this->service->isPopular()); // No bookings yet

        $client = Client::factory()->create();
        $eventType = EventType::factory()->create();
        
        Event::factory()->count(15)->create([
            'client_id' => $client->id,
            'event_type_id' => $eventType->id,
        ])->each(function ($event) {
            $this->service->events()->attach($event->id);
        });

        $this->assertTrue($this->service->isPopular()); // More than 10 bookings
    }

    /** @test */
    public function it_sets_default_values_when_creating()
    {
        $service = Service::create([
            'service_name' => 'Test Service',
            'service_description' => 'Test Description',
            'price' => 100.00,
            'service_category_id' => $this->category->id,
        ]);

        
        $this->assertEquals(0.08, $service->tax_rate);
    }

    /** @test */
    public function it_handles_null_values_gracefully()
    {
        $service = Service::factory()->create([
            'service_category_id' => $this->category->id,
            'max_capacity' => null,
            'min_capacity' => null,
            'setup_time_hours' => null,
            'cleanup_time_hours' => null,
            'advance_notice_hours' => null,
            'tax_rate' => null,
        ]);

        $this->assertEquals(4.0, $service->getTotalDurationWithSetup()); // Only duration_hours
        $this->assertEquals(0, $service->getAdvanceNoticeRequired());
        $this->assertEquals(0, $service->getTaxAmount());
    }
}