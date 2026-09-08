<?php

namespace Tests\Unit\Requests;

use App\Http\Requests\StoreInventoryRequest;
use App\Models\InfentoryCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class StoreInventoryRequestTest extends TestCase
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
        $request = new StoreInventoryRequest();
        $validator = Validator::make([
            'name' => 'Test Inventory Item',
            'inventory_category_id' => 1,
            'price' => 25.99,
            'description' => 'A test inventory item',
            'color' => 'Blue',
            'location' => 'Warehouse A',
            'quantity_available' => 10,
            'is_active' => true
        ], $request->rules());

        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function it_requires_name()
    {
        $request = new StoreInventoryRequest();
        $validator = Validator::make([
            'inventory_category_id' => 1,
            'price' => 25.99,
            'quantity_available' => 10
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    /** @test */
    public function it_requires_category_id()
    {
        $request = new StoreInventoryRequest();
        $validator = Validator::make([
            'name' => 'Test Item',
            'price' => 25.99,
            'quantity_available' => 10
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('inventory_category_id', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_category_exists()
    {
        $request = new StoreInventoryRequest();
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => 999, // Non-existent category
            'price' => 25.99,
            'quantity_available' => 10
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('inventory_category_id', $validator->errors()->toArray());
    }

    /** @test */
    public function it_requires_price()
    {
        $request = new StoreInventoryRequest();
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => 1,
            'quantity_available' => 10
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('price', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_price_is_numeric()
    {
        $request = new StoreInventoryRequest();
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => 1,
            'price' => 'not-a-number',
            'quantity_available' => 10
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('price', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_price_minimum()
    {
        $request = new StoreInventoryRequest();
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => 1,
            'price' => -1,
            'quantity_available' => 10
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('price', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_price_maximum()
    {
        $request = new StoreInventoryRequest();
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => 1,
            'price' => 1000000, // Exceeds max
            'quantity_available' => 10
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('price', $validator->errors()->toArray());
    }

    /** @test */
    public function it_requires_quantity_available()
    {
        $request = new StoreInventoryRequest();
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => 1,
            'price' => 25.99
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('quantity_available', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_quantity_available_is_integer()
    {
        $request = new StoreInventoryRequest();
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => 1,
            'price' => 25.99,
            'quantity_available' => 'not-a-number'
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('quantity_available', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_quantity_available_minimum()
    {
        $request = new StoreInventoryRequest();
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => 1,
            'price' => 25.99,
            'quantity_available' => -1
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('quantity_available', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_quantity_available_maximum()
    {
        $request = new StoreInventoryRequest();
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => 1,
            'price' => 25.99,
            'quantity_available' => 10000 // Exceeds max
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('quantity_available', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_is_active_is_boolean()
    {
        $request = new StoreInventoryRequest();
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => 1,
            'price' => 25.99,
            'quantity_available' => 10,
            'is_active' => 'not-a-boolean'
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('is_active', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_string_field_lengths()
    {
        $request = new StoreInventoryRequest();
        
        // Test name max length
        $validator = Validator::make([
            'name' => str_repeat('a', 256), // Exceeds 255 chars
            'inventory_category_id' => 1,
            'price' => 25.99,
            'quantity_available' => 10
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());

        // Test description max length
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => 1,
            'price' => 25.99,
            'quantity_available' => 10,
            'description' => str_repeat('a', 1001) // Exceeds 1000 chars
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('description', $validator->errors()->toArray());
    }

    /** @test */
    public function it_allows_optional_fields_to_be_null()
    {
        $request = new StoreInventoryRequest();
        $validator = Validator::make([
            'name' => 'Test Item',
            'inventory_category_id' => 1,
            'price' => 25.99,
            'quantity_available' => 10,
            'description' => null,
            'color' => null,
            'location' => null
        ], $request->rules());

        $this->assertTrue($validator->passes());
    }
}