<?php

namespace Tests\Unit\Requests;

use App\Http\Requests\GenerateInvoiceRequest;
use App\Models\Event;
use App\Models\EventInvoice;
use App\Models\Client;
use App\Models\EventType;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Inventory;
use App\Models\InfentoryCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class GenerateInvoiceRequestTest extends TestCase
{
    use RefreshDatabase;

    protected $event;
    protected $service;
    protected $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        
        $client = Client::factory()->create();
        $eventType = EventType::factory()->create();
        
        $this->event = Event::factory()->create([
            'client_id' => $client->id,
            'event_type_id' => $eventType->id,
            'status' => 'confirmed',
        ]);

        $serviceCategory = ServiceCategory::factory()->create();
        $this->service = Service::factory()->create([
            'service_category_id' => $serviceCategory->id,
            'price' => 500.00,
        ]);

        $inventoryCategory = InfentoryCategory::factory()->create();
        $this->inventory = Inventory::factory()->create([
            'inventory_category_id' => $inventoryCategory->id,
            'rental_price' => 100.00,
        ]);

        // Assign service and inventory to event
        $this->event->services()->attach($this->service->id, [
            'quantity' => 2,
            'price' => 450.00,
        ]);

        $this->event->inventories()->attach($this->inventory->id, [
            'quantity' => 3,
        ]);
    }

    /** @test */
    public function it_passes_validation_with_valid_data()
    {
        $request = new GenerateInvoiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            return $route;
        });

        $validator = Validator::make([
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'tax_rate' => 0.08,
            'discount_percentage' => 10.0,
            'notes' => 'Payment terms: Net 30',
            'currency' => 'USD',
            'include_services' => true,
            'include_inventory' => true,
        ], $request->rules());

        $request->withValidator($validator);
        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function it_validates_due_date_is_in_future()
    {
        $request = new GenerateInvoiceRequest();
        
        $validator = Validator::make([
            'due_date' => now()->subDays(1)->format('Y-m-d'),
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('due_date', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_tax_rate_range()
    {
        $request = new GenerateInvoiceRequest();
        
        // Test negative tax rate
        $validator = Validator::make([
            'tax_rate' => -0.05,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('tax_rate', $validator->errors()->toArray());

        // Test excessive tax rate
        $validator = Validator::make([
            'tax_rate' => 1.5,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('tax_rate', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_discount_percentage_range()
    {
        $request = new GenerateInvoiceRequest();
        
        // Test negative discount
        $validator = Validator::make([
            'discount_percentage' => -5.0,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('discount_percentage', $validator->errors()->toArray());

        // Test excessive discount
        $validator = Validator::make([
            'discount_percentage' => 150.0,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('discount_percentage', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_currency_format()
    {
        $request = new GenerateInvoiceRequest();
        
        // Test invalid currency length
        $validator = Validator::make([
            'currency' => 'US',
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('currency', $validator->errors()->toArray());

        // Test invalid currency code
        $validator = Validator::make([
            'currency' => 'XYZ',
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('currency', $validator->errors()->toArray());

        // Test valid currency
        $validator = Validator::make([
            'currency' => 'EUR',
        ], $request->rules());

        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function it_validates_payment_terms_days()
    {
        $request = new GenerateInvoiceRequest();
        
        // Test minimum payment terms
        $validator = Validator::make([
            'payment_terms_days' => 0,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('payment_terms_days', $validator->errors()->toArray());

        // Test maximum payment terms
        $validator = Validator::make([
            'payment_terms_days' => 400,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('payment_terms_days', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_custom_items()
    {
        $request = new GenerateInvoiceRequest();
        
        $validator = Validator::make([
            'custom_items' => [
                [
                    'description' => 'Custom Service',
                    'quantity' => 2,
                    'unit_price' => 150.00,
                    'notes' => 'Special requirements',
                ],
            ],
        ], $request->rules());

        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function it_validates_custom_items_required_fields()
    {
        $request = new GenerateInvoiceRequest();
        
        $validator = Validator::make([
            'custom_items' => [
                [
                    'description' => '', // Missing description
                    'quantity' => 2,
                    'unit_price' => 150.00,
                ],
            ],
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('custom_items.0.description', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_custom_items_limits()
    {
        $request = new GenerateInvoiceRequest();
        
        // Test quantity limits
        $validator = Validator::make([
            'custom_items' => [
                [
                    'description' => 'Test Item',
                    'quantity' => 0, // Below minimum
                    'unit_price' => 100.00,
                ],
            ],
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('custom_items.0.quantity', $validator->errors()->toArray());

        // Test price limits
        $validator = Validator::make([
            'custom_items' => [
                [
                    'description' => 'Test Item',
                    'quantity' => 1,
                    'unit_price' => -50.00, // Negative price
                ],
            ],
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('custom_items.0.unit_price', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_maximum_custom_items()
    {
        $request = new GenerateInvoiceRequest();
        
        $customItems = [];
        for ($i = 0; $i < 51; $i++) { // Exceeds limit of 50
            $customItems[] = [
                'description' => "Item $i",
                'quantity' => 1,
                'unit_price' => 100.00,
            ];
        }

        $validator = Validator::make([
            'custom_items' => $customItems,
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('custom_items', $validator->errors()->toArray());
    }

    /** @test */
    public function it_prevents_invoice_generation_for_existing_invoice()
    {
        // Create existing invoice
        EventInvoice::factory()->create(['event_id' => $this->event->id]);

        $request = new GenerateInvoiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            return $route;
        });

        $validator = Validator::make([], $request->rules());
        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('event', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_event_has_billable_items()
    {
        // Create event without services or inventory
        $emptyEvent = Event::factory()->create([
            'client_id' => $this->event->client_id,
            'event_type_id' => $this->event->event_type_id,
            'status' => 'confirmed',
        ]);

        $request = new GenerateInvoiceRequest();
        $request->setRouteResolver(function () use ($emptyEvent) {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($emptyEvent);
            return $route;
        });

        $validator = Validator::make([
            'include_services' => true,
            'include_inventory' => true,
        ], $request->rules());
        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('event', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_event_status()
    {
        $this->event->update(['status' => 'pending']);

        $request = new GenerateInvoiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            return $route;
        });

        $validator = Validator::make([], $request->rules());
        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('event', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_at_least_one_item_type_included()
    {
        $request = new GenerateInvoiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            return $route;
        });

        $validator = Validator::make([
            'include_services' => false,
            'include_inventory' => false,
        ], $request->rules());
        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('include_services', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_custom_items_total_limit()
    {
        $request = new GenerateInvoiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            return $route;
        });

        $validator = Validator::make([
            'custom_items' => [
                [
                    'description' => 'Expensive Item',
                    'quantity' => 1000,
                    'unit_price' => 1500.00, // Total: 1,500,000 (exceeds 1M limit)
                ],
            ],
        ], $request->rules());
        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('custom_items', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_reasonable_tax_rate()
    {
        $request = new GenerateInvoiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            return $route;
        });

        $validator = Validator::make([
            'tax_rate' => 0.75, // 75% tax rate (exceeds 50% limit)
        ], $request->rules());
        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('tax_rate', $validator->errors()->toArray());
    }

    /** @test */
    public function it_validates_reasonable_due_date()
    {
        $request = new GenerateInvoiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn($this->event);
            return $route;
        });

        $validator = Validator::make([
            'due_date' => now()->addYears(2)->format('Y-m-d'), // More than 1 year
        ], $request->rules());
        $request->withValidator($validator);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('due_date', $validator->errors()->toArray());
    }

    /** @test */
    public function it_sets_default_values()
    {
        $request = new GenerateInvoiceRequest();
        $request->replace([]);
        $request->prepareForValidation();

        $this->assertTrue($request->get('include_services'));
        $this->assertTrue($request->get('include_inventory'));
        $this->assertFalse($request->get('send_to_client'));
        $this->assertFalse($request->get('auto_approve'));
    }

    /** @test */
    public function it_sets_due_date_from_payment_terms()
    {
        $request = new GenerateInvoiceRequest();
        $request->replace(['payment_terms_days' => 45]);
        $request->prepareForValidation();

        $expectedDate = now()->addDays(45)->format('Y-m-d');
        $this->assertEquals($expectedDate, $request->get('due_date'));
    }

    /** @test */
    public function it_formats_tax_rate_from_percentage()
    {
        $request = new GenerateInvoiceRequest();
        $request->replace(['tax_rate' => 8.5]); // 8.5%
        $request->prepareForValidation();

        $this->assertEquals(0.085, $request->get('tax_rate'));
    }

    /** @test */
    public function it_processes_custom_items()
    {
        $request = new GenerateInvoiceRequest();
        $request->replace([
            'custom_items' => [
                [
                    'description' => '  Custom Service  ',
                    'quantity' => '2',
                    'unit_price' => '150.50',
                    'notes' => '  Special notes  ',
                ],
            ],
        ]);
        $request->prepareForValidation();

        $customItems = $request->get('custom_items');
        $this->assertEquals('Custom Service', $customItems[0]['description']);
        $this->assertEquals(2, $customItems[0]['quantity']);
        $this->assertEquals(150.50, $customItems[0]['unit_price']);
        $this->assertEquals('Special notes', $customItems[0]['notes']);
    }

    /** @test */
    public function it_gets_validated_data_with_defaults()
    {
        $request = new GenerateInvoiceRequest();
        $request->replace([
            'discount_percentage' => 15.0,
        ]);

        $validatedData = $request->getValidatedDataWithDefaults();

        $this->assertEquals(0.08, $validatedData['tax_rate']); // Default tax rate
        $this->assertEquals('USD', $validatedData['currency']); // Default currency
        $this->assertArrayHasKey('due_date', $validatedData);
        $this->assertArrayHasKey('terms_and_conditions', $validatedData);
        $this->assertEquals(15.0, $validatedData['discount_percentage']);
    }

    /** @test */
    public function it_generates_invoice_generation_summary()
    {
        $request = new GenerateInvoiceRequest();
        $request->replace([
            'tax_rate' => 0.08,
            'discount_percentage' => 10.0,
            'include_services' => true,
            'include_inventory' => true,
            'custom_items' => [
                [
                    'description' => 'Custom Item',
                    'quantity' => 1,
                    'unit_price' => 200.00,
                ],
            ],
        ]);

        $summary = $request->getInvoiceGenerationSummary($this->event);

        $this->assertArrayHasKey('event', $summary);
        $this->assertArrayHasKey('invoice_settings', $summary);
        $this->assertArrayHasKey('items_to_include', $summary);
        $this->assertArrayHasKey('estimated_totals', $summary);

        $this->assertEquals($this->event->id, $summary['event']['id']);
        $this->assertEquals('8.00%', $summary['invoice_settings']['tax_rate']);
        $this->assertEquals(10.0, $summary['invoice_settings']['discount_percentage']);

        // Check items are included
        $itemTypes = collect($summary['items_to_include'])->pluck('type')->toArray();
        $this->assertContains('service', $itemTypes);
        $this->assertContains('inventory', $itemTypes);
        $this->assertContains('custom', $itemTypes);

        // Check totals are calculated
        $this->assertArrayHasKey('subtotal', $summary['estimated_totals']);
        $this->assertArrayHasKey('discount', $summary['estimated_totals']);
        $this->assertArrayHasKey('tax', $summary['estimated_totals']);
        $this->assertArrayHasKey('total', $summary['estimated_totals']);
    }

    /** @test */
    public function it_allows_null_optional_fields()
    {
        $request = new GenerateInvoiceRequest();
        
        $validator = Validator::make([
            'due_date' => null,
            'tax_rate' => null,
            'discount_percentage' => null,
            'notes' => null,
            'terms_and_conditions' => null,
            'currency' => null,
            'payment_terms_days' => null,
        ], $request->rules());

        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function it_validates_string_length_limits()
    {
        $request = new GenerateInvoiceRequest();
        
        // Test notes length
        $validator = Validator::make([
            'notes' => str_repeat('a', 2001), // Exceeds 2000 chars
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('notes', $validator->errors()->toArray());

        // Test terms and conditions length
        $validator = Validator::make([
            'terms_and_conditions' => str_repeat('a', 5001), // Exceeds 5000 chars
        ], $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('terms_and_conditions', $validator->errors()->toArray());
    }

    /** @test */
    public function it_handles_missing_route_parameters_gracefully()
    {
        $request = new GenerateInvoiceRequest();
        $request->setRouteResolver(function () {
            $route = \Mockery::mock();
            $route->shouldReceive('parameter')->with('event')->andReturn(null);
            return $route;
        });

        $validator = Validator::make([], $request->rules());

        // Should not throw exception when route parameters are missing
        $request->withValidator($validator);
        $this->assertTrue($validator->passes()); // Basic validation should pass
    }
}