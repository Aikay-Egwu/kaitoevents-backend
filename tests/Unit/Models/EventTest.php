<?php

namespace Tests\Unit\Models;

use App\Models\Event;
use App\Models\Client;
use App\Models\EventType;
use App\Models\Service;
use App\Models\Inventory;
use App\Models\EventInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_has_correct_fillable_attributes()
    {
        $fillable = [
            'client_id', 'event_type_id', 'event_date', 'start_time', 'end_time',
            'venue_name', 'venue_address', 'guest_number', 'event_time',
            'budget', 'special_instructions', 'status'
        ];

        $event = new Event();
        $this->assertEquals($fillable, $event->getFillable());
    }

    /** @test */
    public function it_casts_attributes_correctly()
    {
        $event = Event::factory()->create([
            'event_date' => '2024-12-25',
            'start_time' => '2024-12-25 14:00:00',
            'end_time' => '2024-12-25 18:00:00',
            'budget' => 1500.50,
        ]);

        $this->assertInstanceOf(\Carbon\Carbon::class, $event->event_date);
        $this->assertInstanceOf(\Carbon\Carbon::class, $event->start_time);
        $this->assertInstanceOf(\Carbon\Carbon::class, $event->end_time);
        $this->assertEquals('1500.50', $event->budget);
    }

    /** @test */
    public function it_belongs_to_client()
    {
        $client = Client::factory()->create();
        $event = Event::factory()->create(['client_id' => $client->id]);

        $this->assertInstanceOf(Client::class, $event->client);
        $this->assertEquals($client->id, $event->client->id);
    }

    /** @test */
    public function it_belongs_to_event_type()
    {
        $eventType = EventType::factory()->create();
        $event = Event::factory()->create(['event_type_id' => $eventType->id]);

        $this->assertInstanceOf(EventType::class, $event->eventType);
        $this->assertEquals($eventType->id, $event->eventType->id);
    }

    /** @test */
    public function it_has_one_invoice()
    {
        $event = Event::factory()->create();
        $invoice = EventInvoice::factory()->create(['event_id' => $event->id]);

        $this->assertInstanceOf(EventInvoice::class, $event->invoice);
        $this->assertEquals($invoice->id, $event->invoice->id);
    }

    /** @test */
    public function it_has_many_services_through_pivot()
    {
        $event = Event::factory()->create();
        $service = Service::factory()->create();
        
        $event->services()->attach($service->id, ['quantity' => 2, 'price' => 100.00]);

        $this->assertCount(1, $event->services);
        $this->assertEquals($service->id, $event->services->first()->id);
        $this->assertEquals(2, $event->services->first()->pivot->quantity);
        $this->assertEquals(100.00, $event->services->first()->pivot->price);
    }

    /** @test */
    public function it_has_many_inventories_through_pivot()
    {
        $event = Event::factory()->create();
        $inventory = Inventory::factory()->create();
        
        $event->inventories()->attach($inventory->id, ['quantity' => 3]);

        $this->assertCount(1, $event->inventories);
        $this->assertEquals($inventory->id, $event->inventories->first()->id);
        $this->assertEquals(3, $event->inventories->first()->pivot->quantity);
    }

    /** @test */
    public function it_scopes_upcoming_events_correctly()
    {
        // Create past event
        Event::factory()->create([
            'event_date' => now()->subDays(1),
            'start_time' => now()->subDays(1)->setTime(14, 0),
        ]);

        // Create upcoming event
        $upcomingEvent = Event::factory()->create([
            'event_date' => now()->addDays(1),
            'start_time' => now()->addDays(1)->setTime(14, 0),
        ]);

        $upcomingEvents = Event::upcoming()->get();
        
        $this->assertCount(1, $upcomingEvents);
        $this->assertEquals($upcomingEvent->id, $upcomingEvents->first()->id);
    }

    /** @test */
    public function it_scopes_past_events_correctly()
    {
        // Create past event
        $pastEvent = Event::factory()->create([
            'event_date' => now()->subDays(1),
            'end_time' => now()->subDays(1)->setTime(18, 0),
        ]);

        // Create upcoming event
        Event::factory()->create([
            'event_date' => now()->addDays(1),
            'end_time' => now()->addDays(1)->setTime(18, 0),
        ]);

        $pastEvents = Event::past()->get();
        
        $this->assertCount(1, $pastEvents);
        $this->assertEquals($pastEvent->id, $pastEvents->first()->id);
    }

    /** @test */
    public function it_scopes_by_status_correctly()
    {
        Event::factory()->create(['status' => 'pending']);
        Event::factory()->create(['status' => 'confirmed']);
        $completedEvent = Event::factory()->create(['status' => 'completed']);

        $completedEvents = Event::byStatus('completed')->get();
        
        $this->assertCount(1, $completedEvents);
        $this->assertEquals($completedEvent->id, $completedEvents->first()->id);
    }

    /** @test */
    public function it_calculates_total_cost_correctly()
    {
        $event = Event::factory()->create();
        
        // Add services
        $service1 = Service::factory()->create(['price' => 100.00]);
        $service2 = Service::factory()->create(['price' => 200.00]);
        $event->services()->attach($service1->id, ['quantity' => 2, 'price' => 100.00]);
        $event->services()->attach($service2->id, ['quantity' => 1, 'price' => 200.00]);
        
        // Add inventories
        $inventory1 = Inventory::factory()->create(['price' => 50.00]);
        $inventory2 = Inventory::factory()->create(['price' => 75.00]);
        $event->inventories()->attach($inventory1->id, ['quantity' => 3]);
        $event->inventories()->attach($inventory2->id, ['quantity' => 2]);

        $event->load(['services', 'inventories']);
        
        // Services: (100 * 2) + (200 * 1) = 400
        // Inventories: (50 * 3) + (75 * 2) = 300
        // Total: 700
        $this->assertEquals(700.00, $event->calculateTotalCost());
    }

    /** @test */
    public function it_determines_if_event_is_upcoming()
    {
        $upcomingEvent = Event::factory()->create([
            'event_date' => now()->addDays(1),
            'start_time' => now()->addDays(1)->setTime(14, 0),
        ]);

        $pastEvent = Event::factory()->create([
            'event_date' => now()->subDays(1),
            'start_time' => now()->subDays(1)->setTime(14, 0),
        ]);

        $this->assertTrue($upcomingEvent->isUpcoming());
        $this->assertFalse($pastEvent->isUpcoming());
    }

    /** @test */
    public function it_determines_if_event_is_past()
    {
        $pastEvent = Event::factory()->create([
            'event_date' => now()->subDays(1),
            'end_time' => now()->subDays(1)->setTime(18, 0),
        ]);

        $upcomingEvent = Event::factory()->create([
            'event_date' => now()->addDays(1),
            'end_time' => now()->addDays(1)->setTime(18, 0),
        ]);

        $this->assertTrue($pastEvent->isPast());
        $this->assertFalse($upcomingEvent->isPast());
    }

    /** @test */
    public function it_calculates_duration_in_hours()
    {
        $event = Event::factory()->create([
            'start_time' => now()->setTime(14, 0), // 2:00 PM
            'end_time' => now()->setTime(18, 30),   // 6:30 PM
        ]);

        $this->assertEquals(4.5, $event->getDurationHours());
    }

    /** @test */
    public function it_determines_if_event_can_be_modified()
    {
        $pendingEvent = Event::factory()->create(['status' => 'pending']);
        $confirmedEvent = Event::factory()->create(['status' => 'confirmed']);
        $completedEvent = Event::factory()->create(['status' => 'completed']);
        $cancelledEvent = Event::factory()->create(['status' => 'cancelled']);

        $this->assertTrue($pendingEvent->canBeModified());
        $this->assertTrue($confirmedEvent->canBeModified());
        $this->assertFalse($completedEvent->canBeModified());
        $this->assertFalse($cancelledEvent->canBeModified());
    }

    /** @test */
    public function it_determines_if_event_can_be_cancelled()
    {
        $pendingEvent = Event::factory()->create(['status' => 'pending']);
        $confirmedEvent = Event::factory()->create(['status' => 'confirmed']);
        $completedEvent = Event::factory()->create(['status' => 'completed']);
        $cancelledEvent = Event::factory()->create(['status' => 'cancelled']);

        $this->assertTrue($pendingEvent->canBeCancelled());
        $this->assertTrue($confirmedEvent->canBeCancelled());
        $this->assertFalse($completedEvent->canBeCancelled());
        $this->assertFalse($cancelledEvent->canBeCancelled());
    }

    /** @test */
    public function it_determines_if_date_can_be_changed()
    {
        // Pending event - can change date
        $pendingEvent = Event::factory()->create([
            'status' => 'pending',
            'event_date' => now()->addDays(1),
            'start_time' => now()->addDays(1)->setTime(14, 0),
        ]);

        // Confirmed event more than 48 hours away - can change date
        $confirmedEventFuture = Event::factory()->create([
            'status' => 'confirmed',
            'event_date' => now()->addDays(7),
            'start_time' => now()->addDays(7)->setTime(14, 0),
        ]);

        // Confirmed event within 48 hours - cannot change date
        $confirmedEventSoon = Event::factory()->create([
            'status' => 'confirmed',
            'event_date' => now()->addHours(24),
            'start_time' => now()->addHours(24),
        ]);

        // Completed event - cannot change date
        $completedEvent = Event::factory()->create(['status' => 'completed']);

        $this->assertTrue($pendingEvent->canChangeDate());
        $this->assertTrue($confirmedEventFuture->canChangeDate());
        $this->assertFalse($confirmedEventSoon->canChangeDate());
        $this->assertFalse($completedEvent->canChangeDate());
    }

    /** @test */
    public function it_assigns_and_removes_services()
    {
        $event = Event::factory()->create();
        $service = Service::factory()->create(['price' => 100.00]);

        // Assign service
        $event->assignService($service->id, 2, 150.00);
        
        $this->assertCount(1, $event->services);
        $this->assertEquals(2, $event->services->first()->pivot->quantity);
        $this->assertEquals(150.00, $event->services->first()->pivot->price);

        // Remove service
        $event->removeService($service->id);
        $event->refresh();
        
        $this->assertCount(0, $event->services);
    }

    /** @test */
    public function it_assigns_and_removes_inventories()
    {
        $event = Event::factory()->create();
        $inventory = Inventory::factory()->create();

        // Assign inventory
        $event->assignInventory($inventory->id, 3);
        
        $this->assertCount(1, $event->inventories);
        $this->assertEquals(3, $event->inventories->first()->pivot->quantity);

        // Remove inventory
        $event->removeInventory($inventory->id);
        $event->refresh();
        
        $this->assertCount(0, $event->inventories);
    }

    /** @test */
    public function it_validates_status_transitions()
    {
        $event = Event::factory()->create(['status' => 'pending']);

        // Valid transitions
        $this->assertTrue($event->updateStatus('confirmed'));
        $this->assertEquals('confirmed', $event->fresh()->status);

        $this->assertTrue($event->updateStatus('in_progress'));
        $this->assertEquals('in_progress', $event->fresh()->status);

        $this->assertTrue($event->updateStatus('completed'));
        $this->assertEquals('completed', $event->fresh()->status);

        // Invalid transition from completed
        $this->assertFalse($event->updateStatus('pending'));
        $this->assertEquals('completed', $event->fresh()->status);
    }

    /** @test */
    public function it_calculates_services_and_inventories_totals()
    {
        $event = Event::factory()->create();
        
        // Add services
        $service = Service::factory()->create(['price' => 100.00]);
        $event->services()->attach($service->id, ['quantity' => 2, 'price' => 100.00]);
        
        // Add inventories
        $inventory = Inventory::factory()->create(['price' => 50.00]);
        $event->inventories()->attach($inventory->id, ['quantity' => 3]);

        $event->load(['services', 'inventories']);
        
        $this->assertEquals(200.00, $event->getServicesTotal()); // 100 * 2
        $this->assertEquals(150.00, $event->getInventoriesTotal()); // 50 * 3
        $this->assertEquals(350.00, $event->getEstimatedTotal()); // 200 + 150
    }

    /** @test */
    public function it_checks_relationship_existence()
    {
        $event = Event::factory()->create();
        $service = Service::factory()->create();
        $inventory = Inventory::factory()->create();
        $invoice = EventInvoice::factory()->create(['event_id' => $event->id]);

        $this->assertFalse($event->hasServices());
        $this->assertFalse($event->hasInventories());
        $this->assertTrue($event->hasInvoice());

        $event->services()->attach($service->id);
        $event->inventories()->attach($inventory->id);

        $this->assertTrue($event->hasServices());
        $this->assertTrue($event->hasInventories());
    }

    /** @test */
    public function it_sets_default_status_when_creating()
    {
        $event = Event::factory()->create(['status' => null]);
        
        $this->assertEquals('pending', $event->status);
    }

    /** @test */
    public function it_calculates_days_until_event()
    {
        $event = Event::factory()->create([
            'event_date' => now()->addDays(5),
        ]);

        $this->assertEquals(-5, $event->getDaysUntilEvent()); // Negative because it's in the future
    }

    /** @test */
    public function it_calculates_hours_until_event()
    {
        $event = Event::factory()->create([
            'event_date' => now()->addHours(48),
            'start_time' => now()->addHours(48),
        ]);

        $hoursUntil = $event->getHoursUntilEvent();
        $this->assertGreaterThan(40, $hoursUntil);
        $this->assertLessThan(50, $hoursUntil);
    }
}