<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Models\Inventory;
use App\Models\InfentoryCategory;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryControllerTest extends TestCase
{
    use RefreshDatabase;

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
    public function it_requires_authentication_for_admin_routes()
    {
        $response = $this->getJson('/api/admin/inventory');
        $response->assertStatus(401);
    }

    /** @test */
    public function it_can_list_inventory_items()
    {
        Sanctum::actingAs($this->user);
        
        Inventory::factory()->count(3)->create([
            'inventory_category_id' => $this->category->id
        ]);

        $response = $this->getJson('/api/admin/inventory');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'price',
                            'quantity_available',
                            'is_active',
                            'availability_status'
                        ]
                    ],
                    'meta' => [
                        'pagination',
                        'summary'
                    ]
                ]);
    }

    /** @test */
    public function it_can_search_inventory_items()
    {
        Sanctum::actingAs($this->user);
        
        Inventory::factory()->create([
            'name' => 'Round Table',
            'inventory_category_id' => $this->category->id
        ]);
        
        Inventory::factory()->create([
            'name' => 'Square Chair',
            'inventory_category_id' => $this->category->id
        ]);

        $response = $this->getJson('/api/admin/inventory?search=Round');

        $response->assertStatus(200);
        $this->assertEquals(1, count($response->json('data')));
        $this->assertStringContainsString('Round', $response->json('data.0.name'));
    }

    /** @test */
    public function it_can_filter_inventory_by_category()
    {
        Sanctum::actingAs($this->user);
        
        $category2 = InfentoryCategory::factory()->create();
        
        Inventory::factory()->create(['inventory_category_id' => $this->category->id]);
        Inventory::factory()->create(['inventory_category_id' => $category2->id]);

        $response = $this->getJson("/api/admin/inventory?category_id={$this->category->id}");

        $response->assertStatus(200);
        $this->assertEquals(1, count($response->json('data')));
    }

    /** @test */
    public function it_can_filter_inventory_by_price_range()
    {
        Sanctum::actingAs($this->user);
        
        Inventory::factory()->create([
            'price' => 10.00,
            'inventory_category_id' => $this->category->id
        ]);
        
        Inventory::factory()->create([
            'price' => 50.00,
            'inventory_category_id' => $this->category->id
        ]);

        $response = $this->getJson('/api/admin/inventory?min_price=20&max_price=60');

        $response->assertStatus(200);
        $this->assertEquals(1, count($response->json('data')));
    }

    /** @test */
    public function it_can_filter_inventory_by_active_status()
    {
        Sanctum::actingAs($this->user);
        
        Inventory::factory()->create([
            'is_active' => true,
            'inventory_category_id' => $this->category->id
        ]);
        
        Inventory::factory()->create([
            'is_active' => false,
            'inventory_category_id' => $this->category->id
        ]);

        $response = $this->getJson('/api/admin/inventory?is_active=1');

        $response->assertStatus(200);
        $this->assertEquals(1, count($response->json('data')));
        $this->assertTrue($response->json('data.0.is_active'));
    }

    /** @test */
    public function it_can_sort_inventory_items()
    {
        Sanctum::actingAs($this->user);
        
        Inventory::factory()->create([
            'name' => 'Z Item',
            'price' => 10.00,
            'inventory_category_id' => $this->category->id
        ]);
        
        Inventory::factory()->create([
            'name' => 'A Item',
            'price' => 20.00,
            'inventory_category_id' => $this->category->id
        ]);

        // Sort by name ascending
        $response = $this->getJson('/api/admin/inventory?sort_by=name&sort_order=asc');
        $response->assertStatus(200);
        $this->assertEquals('A Item', $response->json('data.0.name'));

        // Sort by price descending
        $response = $this->getJson('/api/admin/inventory?sort_by=price&sort_order=desc');
        $response->assertStatus(200);
        $this->assertEquals('20.00', $response->json('data.0.price'));
    }

    /** @test */
    public function it_can_create_inventory_item()
    {
        Sanctum::actingAs($this->user);

        $inventoryData = [
            'name' => 'Test Inventory Item',
            'inventory_category_id' => $this->category->id,
            'price' => 25.99,
            'description' => 'A test inventory item',
            'color' => 'Blue',
            'location' => 'Warehouse A',
            'quantity_available' => 10,
            'is_active' => true
        ];

        $response = $this->postJson('/api/admin/inventory', $inventoryData);

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'id',
                        'name',
                        'price',
                        'quantity_available',
                        'is_active'
                    ],
                    'message'
                ]);

        $this->assertDatabaseHas('inventories', [
            'name' => 'Test Inventory Item',
            'price' => 25.99
        ]);
    }

    /** @test */
    public function it_validates_required_fields_when_creating()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/admin/inventory', []);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['name', 'inventory_category_id', 'price', 'quantity_available']);
    }

    /** @test */
    public function it_can_show_inventory_item()
    {
        Sanctum::actingAs($this->user);

        $inventory = Inventory::factory()->create([
            'inventory_category_id' => $this->category->id
        ]);

        $response = $this->getJson("/api/admin/inventory/{$inventory->id}");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'id',
                        'name',
                        'price',
                        'quantity_available',
                        'is_active',
                        'category'
                    ]
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
                        'code' => 'NOT_FOUND'
                    ]
                ]);
    }

    /** @test */
    public function it_can_update_inventory_item()
    {
        Sanctum::actingAs($this->user);

        $inventory = Inventory::factory()->create([
            'inventory_category_id' => $this->category->id,
            'name' => 'Original Name'
        ]);

        $updateData = [
            'name' => 'Updated Name',
            'price' => 35.99
        ];

        $response = $this->putJson("/api/admin/inventory/{$inventory->id}", $updateData);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'data' => [
                        'name' => 'Updated Name',
                        'price' => '35.99'
                    ]
                ]);

        $this->assertDatabaseHas('inventories', [
            'id' => $inventory->id,
            'name' => 'Updated Name',
            'price' => 35.99
        ]);
    }

    /** @test */
    public function it_can_delete_inventory_item_not_assigned_to_active_events()
    {
        Sanctum::actingAs($this->user);

        $inventory = Inventory::factory()->create([
            'inventory_category_id' => $this->category->id
        ]);

        $response = $this->deleteJson("/api/admin/inventory/{$inventory->id}");

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true
                ]);

        $this->assertDatabaseMissing('inventories', [
            'id' => $inventory->id
        ]);
    }

    /** @test */
    public function it_prevents_deleting_inventory_assigned_to_active_events()
    {
        Sanctum::actingAs($this->user);

        $inventory = Inventory::factory()->create([
            'inventory_category_id' => $this->category->id
        ]);

        $event = Event::factory()->create([
            'event_date' => now()->addDays(7),
            'status' => 'confirmed'
        ]);

        $inventory->events()->attach($event->id, ['quantity' => 1]);

        $response = $this->deleteJson("/api/admin/inventory/{$inventory->id}");

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'error' => [
                        'code' => 'CONSTRAINT_VIOLATION'
                    ]
                ]);

        $this->assertDatabaseHas('inventories', [
            'id' => $inventory->id
        ]);
    }

    /** @test */
    public function it_can_bulk_update_inventory_items()
    {
        Sanctum::actingAs($this->user);

        $inventory1 = Inventory::factory()->create([
            'inventory_category_id' => $this->category->id,
            'is_active' => true
        ]);

        $inventory2 = Inventory::factory()->create([
            'inventory_category_id' => $this->category->id,
            'quantity_available' => 10
        ]);

        $bulkData = [
            'items' => [
                [
                    'id' => $inventory1->id,
                    'is_active' => false
                ],
                [
                    'id' => $inventory2->id,
                    'quantity_available' => 20
                ]
            ]
        ];

        $response = $this->patchJson('/api/admin/inventory/bulk-update', $bulkData);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'data' => [
                        'updated_count' => 2
                    ]
                ]);

        $this->assertDatabaseHas('inventories', [
            'id' => $inventory1->id,
            'is_active' => false
        ]);

        $this->assertDatabaseHas('inventories', [
            'id' => $inventory2->id,
            'quantity_available' => 20
        ]);
    }

    /** @test */
    public function it_can_get_availability_report()
    {
        Sanctum::actingAs($this->user);

        Inventory::factory()->create([
            'inventory_category_id' => $this->category->id,
            'is_active' => true,
            'quantity_available' => 10
        ]);

        Inventory::factory()->create([
            'inventory_category_id' => $this->category->id,
            'is_active' => false,
            'quantity_available' => 0
        ]);

        Inventory::factory()->create([
            'inventory_category_id' => $this->category->id,
            'is_active' => true,
            'quantity_available' => 3 // Low stock
        ]);

        $response = $this->getJson('/api/admin/inventory-reports/availability');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'summary' => [
                            'total_items',
                            'active_items',
                            'inactive_items',
                            'out_of_stock',
                            'low_stock'
                        ],
                        'by_category',
                        'low_stock_items'
                    ]
                ]);

        $summary = $response->json('data.summary');
        $this->assertEquals(3, $summary['total_items']);
        $this->assertEquals(2, $summary['active_items']);
        $this->assertEquals(1, $summary['inactive_items']);
        $this->assertEquals(1, $summary['out_of_stock']);
        $this->assertEquals(1, $summary['low_stock']);
    }

    /** @test */
    public function it_includes_pagination_metadata()
    {
        Sanctum::actingAs($this->user);

        Inventory::factory()->count(25)->create([
            'inventory_category_id' => $this->category->id
        ]);

        $response = $this->getJson('/api/admin/inventory?per_page=10');

        $response->assertStatus(200)
                ->assertJsonStructure([
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
                ]);

        $pagination = $response->json('meta.pagination');
        $this->assertEquals(1, $pagination['current_page']);
        $this->assertEquals(3, $pagination['total_pages']);
        $this->assertEquals(25, $pagination['total_items']);
        $this->assertEquals(10, $pagination['per_page']);
    }

    /** @test */
    public function it_limits_per_page_to_maximum()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/admin/inventory?per_page=200');

        $response->assertStatus(200);
        $this->assertLessThanOrEqual(100, $response->json('meta.pagination.per_page'));
    }
}