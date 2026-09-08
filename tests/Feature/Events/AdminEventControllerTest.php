<?php

namespace Tests\Feature\Events;

use App\Models\User;
use App\Models\Event;
use App\Models\Client;
use App\Models\EventType;
use App\Models\Service;
use App\Models\Inventory;
use App\Models\InfentoryCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminEventControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $client;
    protected $eventType;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->client = Client::factory()->create();
        $this->eventType = EventType::factory()->create();
    }

    /** @test */
    public function it_requires_authentication_for_admin_routes()
    {
        $response = $this->getJson('/api/admin/events');
        $response->assertStatus(401);
    }

    /** @test */
    public function it_can_list_events_with_filtering()
    {
        Sanctum::actingAs($this->user);
        
        Event::factory()->count(3)->create([
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
            'status' => 'pending',
        ]);

        Event::factory()->create([
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
            'status' => 'confirmed',
        ]);

        $response = $this->getJson('/api/admin/events');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        '*' => [
                            'id',
                            'event_date',
                            'status',
                            'venue_name',
                            'client',
                            'event_type',
                        ]
                    ],
                    'meta' => [
                        'pagination',
                        'summary',
                        'filters_applied',
                    ]
                ]);

        // Test status filtering
        $response = $this->getJson('/api/admin/events?status=pending');
        $response->assertStatus(200);
        $this->assertEquals(3, count($response->json('data')));

        // Test search functionality
        $event = Event::factory()->create([
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
            'venue_name' => 'Grand Ballroom',
        ]);

        $response = $this->getJson('/api/admin/events?search=Grand');
        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    /** @test */
    public function it_can_create_event_with_existing_client()
    {
        Sanctum::actingAs($this->user);

        $eventData = [
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
            'event_date' => now()->addDays(30)->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '18:00',
            'venue_name' => 'Grand Ballroom',
            'venue_address' => '123 Main St, City, State',
            'guest_number' => 150,
            'budget' => 5000.00,
            'special_instructions' => 'Please arrange flowers on each table',
        ];

        $response = $this->postJson('/api/admin/events', $eventData);

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'id',
                        'event_date',
                        'status',
                        'client',
                        'event_type',
                    ],
                    'message'
                ]);

        $this->assertDatabaseHas('events', [
            'client_id' => $this->client->id,
            'venue_name' => 'Grand Ballroom',
            'guest_number' => 150,
        ]);
    }

    /** @test */
    public function it_can_create_event_with_new_client()
    {
        Sanctum::actingAs($this->user);

        $eventData = [
            'client_name' => 'John Doe',
            'client_email' => 'john@example.com',
            'client_phone' => '555-123-4567',
            'event_type_id' => $this->eventType->id,
            'event_date' => now()->addDays(30)->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '18:00',
            'venue_name' => 'Grand Ballroom',
            'venue_address' => '123 Main St, City, State',
            'guest_number' => 150,
        ];

        $response = $this->postJson('/api/admin/events', $eventData);

        $response->assertStatus(201);

        $this->assertDatabaseHas('clients', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $this->assertDatabaseHas('events', [
            'venue_name' => 'Grand Ballroom',
            'guest_number' => 150,
        ]);
    }

    /** @test */
    public function it_validates_event_creation_data()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/admin/events', []);

        $response->assertStatus(422)
                ->assertJsonValidationErrors([
                    'client_name',
                    'event_type_id',
                    'event_date',
                    'start_time',
                    'end_time',
                    'venue_name',
                    'venue_address',
                    'guest_number',
                ]);
    }

    /** @test */
    public function it_can_show_event_with_relationships()
    {
        Sanctum::actingAs($this->user);

        $event = Event::factory()->create([
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
        ]);

        $response = $this->getJson("/api/admin/events/{$event->id}");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'id',
                        'event_date',
                        'status',
                        'client',
                        'event_type',
                        'services',
                        'inventories',
                        'images',
                        'invoice',
                    ]
                ]);
    }

    /** @test */
    public function it_can_update_event()
    {
        Sanctum::actingAs($this->user);

        $event = Event::factory()->create([
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
            'status' => 'pending',
            'venue_name' => 'Original Venue',
        ]);

        $updateData = [
            'venue_name' => 'Updated Venue',
            'guest_number' => 200,
            'status' => 'confirmed',
        ];

        $response = $this->putJson("/api/admin/events/{$event->id}", $updateData);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'data' => [
                        'venue_name' => 'Updated Venue',
                        'guest_number' => 200,
                        'status' => 'confirmed',
                    ]
                ]);

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'venue_name' => 'Updated Venue',
            'guest_number' => 200,
            'status' => 'confirmed',
        ]);
    }

    /** @test */
    public function it_validates_status_transitions_on_update()
    {
        Sanctum::actingAs($this->user);

        $event = Event::factory()->create([
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
            'status' => 'pending',
        ]);

        // Invalid transition: pending -> completed
        $response = $this->putJson("/api/admin/events/{$event->id}", [
            'status' => 'completed',
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['status']);
    }

    /** @test */
    public function it_can_delete_event_when_allowed()
    {
        Sanctum::actingAs($this->user);

        $event = Event::factory()->create([
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
            'status' => 'pending',
        ]);

        $response = $this->deleteJson("/api/admin/events/{$event->id}");

        $response->assertStatus(200)
                ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('events', ['id' => $event->id]);
    }

    /** @test */
    public function it_prevents_deleting_completed_events()
    {
        Sanctum::actingAs($this->user);

        $event = Event::factory()->create([
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
            'status' => 'completed',
        ]);

        $response = $this->deleteJson("/api/admin/events/{$event->id}");

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'error' => [
                        'code' => 'CANNOT_DELETE_EVENT'
                    ]
                ]);

        $this->assertDatabaseHas('events', ['id' => $event->id]);
    }

    /** @test */
    public function it_can_assign_service_to_event()
    {
        Sanctum::actingAs($this->user);

        $event = Event::factory()->create([
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
        ]);

        $service = Service::factory()->create(['price' => 100.00]);

        $response = $this->postJson("/api/admin/events/{$event->id}/assign-service", [
            'service_id' => $service->id,
            'quantity' => 2,
            'custom_price' => 150.00,
        ]);

        $response->assertStatus(200)
                ->assertJson(['success' => true]);

        $this->assertDatabaseHas('event_services', [
            'event_id' => $event->id,
            'service_id' => $service->id,
            'quantity' => 2,
            'price' => 150.00,
        ]);
    }

    /** @test */
    public function it_prevents_assigning_duplicate_services()
    {
        Sanctum::actingAs($this->user);

        $event = Event::factory()->create([
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
        ]);

        $service = Service::factory()->create();
        $event->services()->attach($service->id);

        $response = $this->postJson("/api/admin/events/{$event->id}/assign-service", [
            'service_id' => $service->id,
        ]);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'error' => [
                        'code' => 'SERVICE_ALREADY_ASSIGNED'
                    ]
                ]);
    }

    /** @test */
    public function it_can_remove_service_from_event()
    {
        Sanctum::actingAs($this->user);

        $event = Event::factory()->create([
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
        ]);

        $service = Service::factory()->create();
        $event->services()->attach($service->id);

        $response = $this->deleteJson("/api/admin/events/{$event->id}/services/{$service->id}");

        $response->assertStatus(200)
                ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('event_services', [
            'event_id' => $event->id,
            'service_id' => $service->id,
        ]);
    }

    /** @test */
    public function it_can_assign_inventory_to_event()
    {
        Sanctum::actingAs($this->user);

        $event = Event::factory()->create([
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
        ]);

        $category = InfentoryCategory::factory()->create();
        $inventory = Inventory::factory()->create([
            'inventory_category_id' => $category->id,
            'quantity_available' => 10,
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/admin/events/{$event->id}/assign-inventory", [
            'inventory_id' => $inventory->id,
            'quantity' => 3,
        ]);

        $response->assertStatus(200)
                ->assertJson(['success' => true]);

        $this->assertDatabaseHas('event_inventories', [
            'event_id' => $event->id,
            'inventory_id' => $inventory->id,
            'quantity' => 3,
        ]);
    }

    /** @test */
    public function it_prevents_assigning_unavailable_inventory()
    {
        Sanctum::actingAs($this->user);

        $event = Event::factory()->create([
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
        ]);

        $category = InfentoryCategory::factory()->create();
        $inventory = Inventory::factory()->create([
            'inventory_category_id' => $category->id,
            'quantity_available' => 2,
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/admin/events/{$event->id}/assign-inventory", [
            'inventory_id' => $inventory->id,
            'quantity' => 5, // More than available
        ]);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'error' => [
                        'code' => 'INVENTORY_NOT_AVAILABLE'
                    ]
                ]);
    }

    /** @test */
    public function it_can_remove_inventory_from_event()
    {
        Sanctum::actingAs($this->user);

        $event = Event::factory()->create([
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
        ]);

        $category = InfentoryCategory::factory()->create();
        $inventory = Inventory::factory()->create([
            'inventory_category_id' => $category->id,
        ]);
        $event->inventories()->attach($inventory->id);

        $response = $this->deleteJson("/api/admin/events/{$event->id}/inventory/{$inventory->id}");

        $response->assertStatus(200)
                ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('event_inventories', [
            'event_id' => $event->id,
            'inventory_id' => $inventory->id,
        ]);
    }

    /** @test */
    public function it_can_get_available_services_for_event()
    {
        Sanctum::actingAs($this->user);

        $event = Event::factory()->create([
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
        ]);

        $assignedService = Service::factory()->create(['is_active' => true]);
        $availableService = Service::factory()->create(['is_active' => true]);
        $inactiveService = Service::factory()->create(['is_active' => false]);

        $event->services()->attach($assignedService->id);

        $response = $this->getJson("/api/admin/events/{$event->id}/available-services");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'description',
                            'price',
                        ]
                    ]
                ]);

        $serviceIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($availableService->id, $serviceIds);
        $this->assertNotContains($assignedService->id, $serviceIds);
        $this->assertNotContains($inactiveService->id, $serviceIds);
    }

    /** @test */
    public function it_can_get_available_inventory_for_event()
    {
        Sanctum::actingAs($this->user);

        $event = Event::factory()->create([
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
        ]);

        $category = InfentoryCategory::factory()->create();
        $assignedInventory = Inventory::factory()->create([
            'inventory_category_id' => $category->id,
            'is_active' => true,
            'quantity_available' => 10,
        ]);
        $availableInventory = Inventory::factory()->create([
            'inventory_category_id' => $category->id,
            'is_active' => true,
            'quantity_available' => 5,
        ]);
        $unavailableInventory = Inventory::factory()->create([
            'inventory_category_id' => $category->id,
            'is_active' => false,
            'quantity_available' => 0,
        ]);

        $event->inventories()->attach($assignedInventory->id);

        $response = $this->getJson("/api/admin/events/{$event->id}/available-inventory");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'quantity_available',
                            'category',
                        ]
                    ]
                ]);

        $inventoryIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($availableInventory->id, $inventoryIds);
        $this->assertNotContains($assignedInventory->id, $inventoryIds);
        $this->assertNotContains($unavailableInventory->id, $inventoryIds);
    }

    /** @test */
    public function it_includes_summary_statistics_in_index()
    {
        Sanctum::actingAs($this->user);

        Event::factory()->create([
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
            'status' => 'pending',
        ]);

        Event::factory()->create([
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
            'status' => 'confirmed',
        ]);

        $response = $this->getJson('/api/admin/events');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'meta' => [
                        'summary' => [
                            'total_events',
                            'pending_events',
                            'confirmed_events',
                            'in_progress_events',
                            'completed_events',
                            'cancelled_events',
                            'upcoming_events',
                            'past_events',
                        ]
                    ]
                ]);

        $summary = $response->json('meta.summary');
        $this->assertEquals(2, $summary['total_events']);
        $this->assertEquals(1, $summary['pending_events']);
        $this->assertEquals(1, $summary['confirmed_events']);
    }

    /** @test */
    public function it_supports_pagination_and_sorting()
    {
        Sanctum::actingAs($this->user);

        Event::factory()->count(25)->create([
            'client_id' => $this->client->id,
            'event_type_id' => $this->eventType->id,
        ]);

        // Test pagination
        $response = $this->getJson('/api/admin/events?per_page=10');
        $response->assertStatus(200);
        
        $pagination = $response->json('meta.pagination');
        $this->assertEquals(10, $pagination['per_page']);
        $this->assertEquals(25, $pagination['total_items']);
        $this->assertEquals(3, $pagination['total_pages']);

        // Test sorting
        $response = $this->getJson('/api/admin/events?sort_by=created_at&sort_order=desc');
        $response->assertStatus(200);
        
        $events = $response->json('data');
        $this->assertGreaterThan(0, count($events));
    }
}