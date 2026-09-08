<?php

namespace Tests\Unit\Requests;

use App\Http\Requests\UpdateInventoryRequest;
use App\Models\InfentoryCategory;
use App\Models\Inventory;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UpdateInventoryRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a test category
        InfentoryCategory::factory()->create([
            'id' => 1,
            'category_name' => 'Test Category'
        ]);
    }

    /** @test */
    public function it_passes_validation_with_valid_data()
    {
        $request = new UpdateInventoryRequest();
        $validator = Validator::make([
            'name' => 'Updated Inventory Item',
            'inventory_category_id' => 1,
            'price' => 35.99,
            'description' => 'An updated test inventory item',
            'color' => 'Red',
            'location' => 'Warehouse B',
            'quantity_available' => 15,
            'is_active' => true
        ], $request->rules());

        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function it_allows_partial_updates()
    {
        $request = new UpdateInventoryRequest();
        $validator = Validator::make([
            'name' => 'Updated Name Only'
        ], $request->rules());

        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function it_validates_name_when_provided()
    {
        $request = new UpdateInventoryRequest();
        $validator = Validator::make([
            'name' => '' // Empty string should fail
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_category_exists_when_provided()
    {
        $request = new UpdateInventoryRequest();
        $validator = Validator::make([
            'inventory_category_id' => 999 // Non-existent category
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('inventory_category_id', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_price_when_provided()
    {
        $request = new UpdateInventoryRequest();
        
        // Test negative price
        $validator = Validator::make([
            'price' => -1
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('price', $validator->errors()->toArray());

        // Test non-numeric price
        $validator = Validator::make([
            'price' => 'not-a-number'
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('price', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_quantity_available_when_provided()
    {
        $request = new UpdateInventoryRequest();
        
        // Test negative quantity
        $validator = Validator::make([
            'quantity_available' => -1
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('quantity_available', $validator->errors()->toArray());

        // Test non-integer quantity
        $validator = Validator::make([
            'quantity_available' => 'not-a-number'
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('quantity_available', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_is_active_when_provided()
    {
        $request = new UpdateInventoryRequest();
        $validator = Validator::make([
            'is_active' => 'not-a-boolean'
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('is_active', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_string_field_lengths()
    {
        $request = new UpdateInventoryRequest();
        
        // Test name max length
        $validator = Validator::make([
            'name' => str_repeat('a', 256) // Exceeds 255 chars
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());

        // Test description max length
        $validator = Validator::make([
            'description' => str_repeat('a', 1001) // Exceeds 1000 chars
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('description', $validator->errors()->toArray());

        // Test color max length
        $validator = Validator::make([
            'color' => str_repeat('a', 101) // Exceeds 100 chars
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('color', $validator->errors()->toArray());

        // Test location max length
        $validator = Validator::make([
            'location' => str_repeat('a', 256) // Exceeds 255 chars
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('location', $validator->errors()->toArray());
    }

    /** @test */
    public function it_allows_null_values_for_optional_fields()
    {
        $request = new UpdateInventoryRequest();
        $validator = Validator::make([
            'description' => null,
            'color' => null,
            'location' => null
        ], $request->rules());

        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function it_prevents_deactivating_inventory_assigned_to_active_events()
    {
        // Create inventory item
        $inventory = Inventory::factory()->create([
            'is_active' => true,
            'inventory_category_id' => 1
        ]);

        // Create an active event
        $event = Event::factory()->create([
            'event_date' => now()->addDays(7),
            'status' => 'confirmed'
        ]);

        // Assign inventory to event
        $inventory->events()->attach($event->id, ['quantity' => 1]);

        // Create request with inventory in route
        $request = new UpdateInventoryRequest();
        $request->setRouteResolver(function () use ($inventory) {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('inventory')->andReturn($inventory);
            return $route;
        });

        $validator = Validator::make([
            'is_active' => false
        ], $request->rules());

        // Apply the withValidator method
        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('is_active', $validator->errors()->toArray());
        $this->assertStringContainsString('Cannot deactivate inventory item', $validator->errors()->first('is_active'));
    }

    /** @test */
    public function it_allows_deactivating_inventory_not_assigned_to_active_events()
    {
        // Create inventory item
        $inventory = Inventory::factory()->create([
            'is_active' => true,
            'inventory_category_id' => 1
        ]);

        // Create request with inventory in route
        $request = new UpdateInventoryRequest();
        $request->setRouteResolver(function () use ($inventory) {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('inventory')->andReturn($inventory);
            return $route;
        });

        $validator = Validator::make([
            'is_active' => false
        ], $request->rules());

        // Apply the withValidator method
        $request->withValidator($validator);

        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function it_allows_activating_inventory_regardless_of_event_assignments()
    {
        // Create inventory item
        $inventory = Inventory::factory()->create([
            'is_active' => false,
            'inventory_category_id' => 1
        ]);

        // Create an active event
        $event = Event::factory()->create([
            'event_date' => now()->addDays(7),
            'status' => 'confirmed'
        ]);

        // Assign inventory to event
        $inventory->events()->attach($event->id, ['quantity' => 1]);

        // Create request with inventory in route
        $request = new UpdateInventoryRequest();
        $request->setRouteResolver(function () use ($inventory) {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('inventory')->andReturn($inventory);
            return $route;
        });

        $validator = Validator::make([
            'is_active' => true
        ], $request->rules());

        // Apply the withValidator method
        $request->withValidator($validator);

        $this->assertTrue($validator->passes());
    }
}