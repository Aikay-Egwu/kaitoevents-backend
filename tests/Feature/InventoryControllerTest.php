<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\InfentoryCategory;
use App\Models\Inventory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $user;
    protected $category;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->category = InfentoryCategory::factory()->create([
            'category_name' => 'Test Category'
        ]);
    }

    /** @test */
    public function it_can_list_inventory_items()
    {
        Sanctum::actingAs($this->user);

        $inventory1 = Inventory::factory()->create([
            'name' => 'Test Item 1',
            'inventory_category_id' => $this->category->id,
            'price' => 100.00,
            'location' => 'Warehouse A'
        ]);

        $inventory2 = Inventory::factory()->create([
            'name' => 'Test Item 2',
            'inventory_category_id' => $this->category->id,
            'price' => 200.00,
            'location' => 'Warehouse B'
        ]);

        $response = $this->getJson('/api/admin/inventory');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'inventory_category_id',
                            'category',
                            'price',
                            'price_raw',
                            'description',
                            'color',
                            'location',
                            'created_at',
                            'updated_at',
                            'events_count'
                        ]
                    ],
                    'meta' => [
                        'pagination' => [
                            'current_page',
                            'total_pages',
                            'total_items',
                            'per_page',
                            'from',
                            'to'
                        ]
                    ]
                ])
                ->assertJson([
                    'success' => true
                ]);
    }

    /** @test */
    public function it_can_search_inventory_items()
    {
        Sanctum::actingAs($this->user);

        $inventory1 = Inventory::factory()->create([
            'name' => 'Red Chair',
            'inventory_category_id' => $this->category->id,
            'description' => 'Comfortable red chair'
        ]);

        $inventory2 = Inventory::factory()->create([
            'name' => 'Blue Table',
            'inventory_category_id' => $this->category->id,
            'description' => 'Large blue table'
        ]);

        $response = $this->getJson('/api/admin/inventory?search=red');

        $response->assertStatus(200)
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.name', 'Red Chair');
    }

    /** @test */
    public function it_can_filter_inventory_by_category()
    {
        Sanctum::actingAs($this->user);

        $category2 = InfentoryCategory::factory()->create(['category_name' => 'Category 2']);

        $inventory1 = Inventory::factory()->create([
            'inventory_category_id' => $this->category->id
        ]);

        $inventory2 = Inventory::factory()->create([
            'inventory_category_id' => $category2->id
        ]);

        $response = $this->getJson("/api/admin/inventory?category_id={$this->category->id}");

        $response->assertStatus(200)
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.inventory_category_id', $this->category->id);
    }

    /** @test */
    public function it_can_filter_inventory_by_price_range()
    {
        Sanctum::actingAs($this->user);

        $inventory1 = Inventory::factory()->create([
            'inventory_category_id' => $this->category->id,
            'price' => 50.00
        ]);

        $inventory2 = Inventory::factory()->create([
            'inventory_category_id' => $this->category->id,
            'price' => 150.00
        ]);

        $inventory3 = Inventory::factory()->create([
            'inventory_category_id' => $this->category->id,
            'price' => 250.00
        ]);

        $response = $this->getJson('/api/admin/inventory?min_price=100&max_price=200');

        $response->assertStatus(200)
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.price_raw', 150.00);
    }

    /** @test */
    public function it_can_create_inventory_item()
    {
        Sanctum::actingAs($this->user);

        $inventoryData = [
            'name' => 'New Test Item',
            'inventory_category_id' => $this->category->id,
            'price' => 99.99,
            'description' => 'A test inventory item',
            'color' => 'Blue',
            'location' => 'Storage Room A'
        ];

        $response = $this->postJson('/api/admin/inventory', $inventoryData);

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'id',
                        'name',
                        'inventory_category_id',
                        'category',
                        'price',
                        'price_raw',
                        'description',
                        'color',
                        'location'
                    ],
                    'message'
                ])
                ->assertJson([
                    'success' => true,
                    'data' => [
                        'name' => 'New Test Item',
                        'price_raw' => 99.99,
                        'description' => 'A test inventory item',
                        'color' => 'Blue',
                        'location' => 'Storage Room A'
                    ],
                    'message' => 'Inventory item created successfully.'
                ]);

        $this->assertDatabaseHas('inventories', [
            'name' => 'New Test Item',
            'inventory_category_id' => $this->category->id,
            'price' => 99.99
        ]);
    }

    /** @test */
    public function it_validates_required_fields_when_creating_inventory()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/admin/inventory', []);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['name', 'inventory_category_id', 'price']);
    }

    /** @test */
    public function it_can_show_inventory_item_with_events()
    {
        Sanctum::actingAs($this->user);

        $inventory = Inventory::factory()->create([
            'inventory_category_id' => $this->category->id
        ]);

        $event = Event::factory()->create([
            'name' => 'Test Event',
            'event_date' => now()->addDays(7),
            'status' => 'confirmed'
        ]);

        $inventory->events()->attach($event->id);

        $response = $this->getJson("/api/admin/inventory/{$inventory->id}");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'id',
                        'name',
                        'category',
                        'current_events',
                        'events_count'
                    ],
                    'message'
                ])
                ->assertJson([
                    'success' => true,
                    'message' => 'Inventory item retrieved successfully.'
                ]);
    }

    /** @test */
    public function it_returns_404_for_non_existent_inventory_item()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/admin/inventory/999');

        $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'error' => [
                        'message' => 'Inventory item not found.',
                        'code' => 'NOT_FOUND'
                    ]
                ]);
    }

    /** @test */
    public function it_can_update_inventory_item()
    {
        Sanctum::actingAs($this->user);

        $inventory = Inventory::factory()->create([
            'name' => 'Original Name',
            'inventory_category_id' => $this->category->id,
            'price' => 100.00
        ]);

        $updateData = [
            'name' => 'Updated Name',
            'price' => 150.00,
            'description' => 'Updated description'
        ];

        $response = $this->putJson("/api/admin/inventory/{$inventory->id}", $updateData);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'data' => [
                        'name' => 'Updated Name',
                        'price_raw' => 150.00,
                        'description' => 'Updated description'
                    ],
                    'message' => 'Inventory item updated successfully.'
                ]);

        $this->assertDatabaseHas('inventories', [
            'id' => $inventory->id,
            'name' => 'Updated Name',
            'price' => 150.00,
            'description' => 'Updated description'
        ]);
    }

    /** @test */
    public function it_can_delete_inventory_item_when_not_assigned_to_active_events()
    {
        Sanctum::actingAs($this->user);

        $inventory = Inventory::factory()->create([
            'inventory_category_id' => $this->category->id
        ]);

        // Create a past event (should not prevent deletion)
        $pastEvent = Event::factory()->create([
            'event_date' => now()->subDays(7),
            'status' => 'completed'
        ]);
        $inventory->events()->attach($pastEvent->id);

        $response = $this->deleteJson("/api/admin/inventory/{$inventory->id}");

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Inventory item deleted successfully.'
                ]);

        $this->assertDatabaseMissing('inventories', ['id' => $inventory->id]);
    }

    /** @test */
    public function it_prevents_deletion_when_assigned_to_active_events()
    {
        Sanctum::actingAs($this->user);

        $inventory = Inventory::factory()->create([
            'inventory_category_id' => $this->category->id
        ]);

        // Create an active event (should prevent deletion)
        $activeEvent = Event::factory()->create([
            'name' => 'Active Event',
            'event_date' => now()->addDays(7),
            'status' => 'confirmed'
        ]);
        $inventory->events()->attach($activeEvent->id);

        $response = $this->deleteJson("/api/admin/inventory/{$inventory->id}");

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'error' => [
                        'message' => 'Cannot delete inventory item. It is currently assigned to active events.',
                        'code' => 'CONSTRAINT_VIOLATION'
                    ]
                ])
                ->assertJsonStructure([
                    'error' => [
                        'details' => [
                            'active_events' => [
                                '*' => ['id', 'name', 'event_date', 'status']
                            ]
                        ]
                    ]
                ]);

        $this->assertDatabaseHas('inventories', ['id' => $inventory->id]);
    }

    /** @test */
    public function it_can_sort_inventory_items()
    {
        Sanctum::actingAs($this->user);

        $inventory1 = Inventory::factory()->create([
            'name' => 'A Item',
            'inventory_category_id' => $this->category->id,
            'price' => 200.00
        ]);

        $inventory2 = Inventory::factory()->create([
            'name' => 'B Item',
            'inventory_category_id' => $this->category->id,
            'price' => 100.00
        ]);

        // Sort by price ascending
        $response = $this->getJson('/api/admin/inventory?sort_by=price&sort_order=asc');

        $response->assertStatus(200)
                ->assertJsonPath('data.0.price_raw', 100.00)
                ->assertJsonPath('data.1.price_raw', 200.00);

        // Sort by name descending
        $response = $this->getJson('/api/admin/inventory?sort_by=name&sort_order=desc');

        $response->assertStatus(200)
                ->assertJsonPath('data.0.name', 'B Item')
                ->assertJsonPath('data.1.name', 'A Item');
    }

    /** @test */
    public function it_can_filter_available_only_inventory()
    {
        Sanctum::actingAs($this->user);

        $availableInventory = Inventory::factory()->create([
            'name' => 'Available Item',
            'inventory_category_id' => $this->category->id
        ]);

        $assignedInventory = Inventory::factory()->create([
            'name' => 'Assigned Item',
            'inventory_category_id' => $this->category->id
        ]);

        // Create an active event and assign inventory
        $activeEvent = Event::factory()->create([
            'event_date' => now()->addDays(7),
            'status' => 'confirmed'
        ]);
        $assignedInventory->events()->attach($activeEvent->id);

        $response = $this->getJson('/api/admin/inventory?available_only=1');

        $response->assertStatus(200)
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.name', 'Available Item');
    }

    /** @test */
    public function it_requires_authentication_for_all_endpoints()
    {
        $inventory = Inventory::factory()->create([
            'inventory_category_id' => $this->category->id
        ]);

        // Test all endpoints without authentication
        $this->getJson('/api/admin/inventory')->assertStatus(401);
        $this->postJson('/api/admin/inventory', [])->assertStatus(401);
        $this->getJson("/api/admin/inventory/{$inventory->id}")->assertStatus(401);
        $this->putJson("/api/admin/inventory/{$inventory->id}", [])->assertStatus(401);
        $this->deleteJson("/api/admin/inventory/{$inventory->id}")->assertStatus(401);
    }
}