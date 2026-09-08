<?php

namespace Tests\Unit;

use App\Http\Requests\StoreInventoryRequest;
use App\Http\Requests\UpdateInventoryRequest;
use App\Models\InfentoryCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class InventoryValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a test category for validation tests
        $this->testCategory = InfentoryCategory::factory()->create();
    }

    /** @test */
    public function store_inventory_request_validates_required_fields()
    {
        $request = new StoreInventoryRequest();
        $validator = Validator::make([], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
        $this->assertArrayHasKey('inventory_category_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('price', $validator->errors()->toArray());
    }

    /** @test */
    public function store_inventory_request_validates_name_field()
    {
        $request = new StoreInventoryRequest();
        
        // Test empty name
        $validator = Validator::make(['name' => ''], $request->rules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());

        // Test name too long
        $validator = Validator::make(['name' => str_repeat('a', 256)], $request->rules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());

        // Test valid name
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => $this->testCategory->id,
            'price' => 10.99
        ], $request->rules());
        $this->assertFalse($validator->fails());
    }

    /** @test */
    public function store_inventory_request_validates_category_field()
    {
        $request = new StoreInventoryRequest();
        
        // Test non-existent category
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => 99999,
            'price' => 10.99
        ], $request->rules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('inventory_category_id', $validator->errors()->toArray());

        // Test valid category
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => $this->testCategory->id,
            'price' => 10.99
        ], $request->rules());
        $this->assertFalse($validator->fails());
    }

    /** @test */
    public function store_inventory_request_validates_price_field()
    {
        $request = new StoreInventoryRequest();
        
        // Test negative price
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => $this->testCategory->id,
            'price' => -10.99
        ], $request->rules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('price', $validator->errors()->toArray());

        // Test price too high
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => $this->testCategory->id,
            'price' => 1000000.00
        ], $request->rules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('price', $validator->errors()->toArray());

        // Test valid price
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => $this->testCategory->id,
            'price' => 10.99
        ], $request->rules());
        $this->assertFalse($validator->fails());
    }

    /** @test */
    public function store_inventory_request_validates_optional_fields()
    {
        $request = new StoreInventoryRequest();
        
        // Test description too long
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => $this->testCategory->id,
            'price' => 10.99,
            'description' => str_repeat('a', 1001)
        ], $request->rules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('description', $validator->errors()->toArray());

        // Test color too long
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => $this->testCategory->id,
            'price' => 10.99,
            'color' => str_repeat('a', 101)
        ], $request->rules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('color', $validator->errors()->toArray());

        // Test location too long
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => $this->testCategory->id,
            'price' => 10.99,
            'location' => str_repeat('a', 256)
        ], $request->rules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('location', $validator->errors()->toArray());

        // Test valid optional fields
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => $this->testCategory->id,
            'price' => 10.99,
            'description' => 'A test description',
            'color' => 'Red',
            'location' => 'Warehouse A'
        ], $request->rules());
        $this->assertFalse($validator->fails());
    }

    /** @test */
    public function update_inventory_request_validates_partial_updates()
    {
        $request = new UpdateInventoryRequest();
        
        // Test updating only name
        $validator = Validator::make([
            'name' => 'Updated Item'
        ], $request->rules());
        $this->assertFalse($validator->fails());

        // Test updating only price
        $validator = Validator::make([
            'price' => 15.99
        ], $request->rules());
        $this->assertFalse($validator->fails());

        // Test updating with invalid data
        $validator = Validator::make([
            'name' => '',
            'price' => -5.00
        ], $request->rules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
        $this->assertArrayHasKey('price', $validator->errors()->toArray());
    }

    /** @test */
    public function update_inventory_request_allows_empty_request()
    {
        $request = new UpdateInventoryRequest();
        
        // Empty update should be valid (no required fields)
        $validator = Validator::make([], $request->rules());
        $this->assertFalse($validator->fails());
    }

    /** @test */
    public function validation_messages_are_user_friendly()
    {
        $request = new StoreInventoryRequest();
        $messages = $request->messages();

        $this->assertArrayHasKey('name.required', $messages);
        $this->assertArrayHasKey('inventory_category_id.required', $messages);
        $this->assertArrayHasKey('price.required', $messages);
        $this->assertArrayHasKey('price.min', $messages);
        
        $this->assertStringContainsString('inventory item name', $messages['name.required']);
        $this->assertStringContainsString('category', $messages['inventory_category_id.required']);
        $this->assertStringContainsString('price', $messages['price.required']);
    }
}