<?php

namespace Tests\Unit\Resources;

use App\Http\Resources\InventoryResource;
use App\Models\Inventory;
use App\Models\InfentoryCategory;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class InventoryResourceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_transforms_inventory_data_correctly()
    {
        $category = InfentoryCategory::factory()->create([
            'category_name' => 'Test Category'
        ]);

        $inventory = Inventory::factory()->create([
            'name' => 'Test Item',
            'inventory_category_id' => $category->id,
            'price' => 25.99,
            'description' => 'Test description',
            'color' => 'Blue',
            'location' => 'Warehouse A',
            'quantity_available' => 10,
            'is_active' => true
        ]);

        $inventory->load('category');

        $resource = new InventoryResource($inventory);
        $request = Request::create('/test');
        $data = $resource->toArray($request);

        $this->assertEquals($inventory->id, $data['id']);
        $this->assertEquals('Test Item', $data['name']);
        $this->assertEquals('25.99', $data['price']);
        $this->assertEquals(25.99, $data['price_raw']);
        $this->assertEquals('Test description', $data['description']);
        $this->assertEquals('Blue', $data['color']);
        $this->assertEquals('Warehouse A', $data['location']);
        $this->assertEquals(10, $data['quantity_available']);
        $this->assertTrue($data['is_active']);
        $this->assertEquals('available', $data['availability_status']);
        
        $this->assertArrayHasKey('category', $data);
        $this->assertEquals($category->id, $data['category']['id']);
        $this->assertEquals('Test Category', $data['category']['name']);
    }

    /** @test */
    public function it_calculates_availability_status_correctly()
    {
        $category = InfentoryCategory::factory()->create();

        // Test inactive item
        $inactiveInventory = Inventory::factory()->create([
            'inventory_category_id' => $category->id,
            'is_active' => false,
            'quantity_available' => 10
        ]);

        $resource = new InventoryResource($inactiveInventory);
        $data = $resource->toArray(Request::create('/test'));
        $this->assertEquals('inactive', $data['availability_status']);

        // Test out of stock
        $outOfStockInventory = Inventory::factory()->create([
            'inventory_category_id' => $category->id,
            'is_active' => true,
            'quantity_available' => 0
        ]);

        $resource = new InventoryResource($outOfStockInventory);
        $data = $resource->toArray(Request::create('/test'));
        $this->assertEquals('out_of_stock', $data['availability_status']);

        // Test low availability
        $lowStockInventory = Inventory::factory()->create([
            'inventory_category_id' => $category->id,
            'is_active' => true,
            'quantity_available' => 3
        ]);

        $resource = new InventoryResource($lowStockInventory);
        $data = $resource->toArray(Request::create('/test'));
        $this->assertEquals('low_availability', $data['availability_status']);

        // Test available
        $availableInventory = Inventory::factory()->create([
            'inventory_category_id' => $category->id,
            'is_active' => true,
            'quantity_available' => 20
        ]);

        $resource = new InventoryResource($availableInventory);
        $data = $resource->toArray(Request::create('/test'));
        $this->assertEquals('available', $data['availability_status']);
    }

    /** @test */
    public function it_includes_current_events_when_loaded()
    {
        $category = InfentoryCategory::factory()->create();
        $inventory = Inventory::factory()->create([
            'inventory_category_id' => $category->id
        ]);

        $upcomingEvent = Event::factory()->create([
            'event_date' => now()->addDays(7),
            'status' => 'confirmed'
        ]);

        $pastEvent = Event::factory()->create([
            'event_date' => now()->subDays(7),
            'status' => 'completed'
        ]);

        $inventory->events()->attach($upcomingEvent->id, ['quantity' => 2]);
        $inventory->events()->attach($pastEvent->id, ['quantity' => 1]);

        $inventory->load('events');

        $resource = new InventoryResource($inventory);
        $data = $resource->toArray(Request::create('/test'));

        $this->assertArrayHasKey('current_events', $data);
        $this->assertCount(1, $data['current_events']); // Only upcoming event
        $this->assertEquals($upcomingEvent->id, $data['current_events'][0]['id']);
        $this->assertEquals(2, $data['current_events'][0]['quantity_assigned']);
    }

    /** @test */
    public function it_includes_usage_statistics_when_requested()
    {
        $category = InfentoryCategory::factory()->create();
        $inventory = Inventory::factory()->create([
            'inventory_category_id' => $category->id,
            'price' => 10.00
        ]);

        // Create events
        $upcomingEvent = Event::factory()->create([
            'event_date' => now()->addDays(7),
            'status' => 'confirmed'
        ]);

        $completedEvent = Event::factory()->create([
            'event_date' => now()->subDays(7),
            'status' => 'completed'
        ]);

        $inventory->events()->attach($upcomingEvent->id, ['quantity' => 2]);
        $inventory->events()->attach($completedEvent->id, ['quantity' => 3]);

        $request = Request::create('/test?include_stats=1');
        $resource = new InventoryResource($inventory);
        $data = $resource->toArray($request);

        $this->assertArrayHasKey('usage_statistics', $data);
        $this->assertEquals(2, $data['usage_statistics']['total_events']);
        $this->assertEquals(1, $data['usage_statistics']['upcoming_events']);
        $this->assertEquals(1, $data['usage_statistics']['past_events']);
        $this->assertEquals(30.00, $data['usage_statistics']['revenue_generated']); // 3 * $10.00
    }

    /** @test */
    public function it_handles_missing_relationships_gracefully()
    {
        $inventory = Inventory::factory()->create([
            'inventory_category_id' => 1 // Assuming this doesn't exist
        ]);

        $resource = new InventoryResource($inventory);
        $data = $resource->toArray(Request::create('/test'));

        $this->assertArrayNotHasKey('category', $data);
        $this->assertArrayNotHasKey('current_events', $data);
    }

    /** @test */
    public function it_formats_timestamps_correctly()
    {
        $inventory = Inventory::factory()->create([
            'inventory_category_id' => InfentoryCategory::factory()->create()->id
        ]);

        $resource = new InventoryResource($inventory);
        $data = $resource->toArray(Request::create('/test'));

        $this->assertArrayHasKey('created_at', $data);
        $this->assertArrayHasKey('updated_at', $data);
        
        // Check ISO format
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $data['created_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $data['updated_at']);
    }
}