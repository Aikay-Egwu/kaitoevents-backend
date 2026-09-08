<?php

namespace Tests\Unit\Models;

use App\Models\InvoiceItem;
use App\Models\EventInvoice;
use App\Models\Service;
use App\Models\Inventory;
use App\Models\ServiceCategory;
use App\Models\InfentoryCategory;
use App\Models\Event;
use App\Models\Client;
use App\Models\EventType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceItemTest extends TestCase
{
    use RefreshDatabase;

    protected $invoiceItem;
    protected $invoice;
    protected $service;
    protected $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        
        $client = Client::factory()->create();
        $eventType = EventType::factory()->create();
        $event = Event::factory()->create([
            'client_id' => $client->id,
            'event_type_id' => $eventType->id,
        ]);

        $this->invoice = EventInvoice::factory()->create([
            'event_id' => $event->id,
        ]);

        $serviceCategory = ServiceCategory::factory()->create();
        $this->service = Service::factory()->create([
            'service_category_id' => $serviceCategory->id,
            'price' => 100.00,
        ]);

        $inventoryCategory = InfentoryCategory::factory()->create();
        $this->inventory = Inventory::factory()->create([
            'inventory_category_id' => $inventoryCategory->id,
            'rental_price' => 50.00,
        ]);

        $this->invoiceItem = InvoiceItem::factory()->create([
            'event_invoice_id' => $this->invoice->id,
            'item_type' => 'service',
            'item_id' => $this->service->id,
            'quantity' => 2,
            'unit_price' => 100.00,
            'discount_percentage' => 10.00,
            'tax_rate' => 0.08,
        ]);
    }

    /** @test */
    public function it_has_correct_fillable_attributes()
    {
        $fillable = [
            'event_invoice_id', 'item_type', 'item_id', 'description', 'quantity',
            'unit_price', 'total_price', 'discount_percentage', 'discount_amount',
            'tax_rate', 'tax_amount', 'notes', 'sort_order'
        ];

        $item = new InvoiceItem();
        $this->assertEquals($fillable, $item->getFillable());
    }

    /** @test */
    public function it_casts_attributes_correctly()
    {
        $item = InvoiceItem::factory()->create([
            'quantity' => '5',
            'unit_price' => 123.456,
            'discount_percentage' => 15.678,
            'tax_rate' => 0.0875,
            'sort_order' => '3',
        ]);

        $this->assertIsInt($item->quantity);
        $this->assertEquals(5, $item->quantity);
        $this->assertEquals('123.46', $item->unit_price);
        $this->assertEquals('15.68', $item->discount_percentage);
        $this->assertEquals('0.0875', $item->tax_rate);
        $this->assertIsInt($item->sort_order);
        $this->assertEquals(3, $item->sort_order);
    }

    /** @test */
    public function it_belongs_to_invoice()
    {
        $this->assertInstanceOf(EventInvoice::class, $this->invoiceItem->invoice);
        $this->assertEquals($this->invoice->id, $this->invoiceItem->invoice->id);
    }

    /** @test */
    public function it_belongs_to_service_when_item_type_is_service()
    {
        $this->assertInstanceOf(Service::class, $this->invoiceItem->service);
        $this->assertEquals($this->service->id, $this->invoiceItem->service->id);
    }

    /** @test */
    public function it_belongs_to_inventory_when_item_type_is_inventory()
    {
        $inventoryItem = InvoiceItem::factory()->create([
            'event_invoice_id' => $this->invoice->id,
            'item_type' => 'inventory',
            'item_id' => $this->inventory->id,
        ]);

        $this->assertInstanceOf(Inventory::class, $inventoryItem->inventory);
        $this->assertEquals($this->inventory->id, $inventoryItem->inventory->id);
    }

    /** @test */
    public function it_returns_correct_item_based_on_type()
    {
        // Service item
        $serviceItem = $this->invoiceItem->item();
        $this->assertInstanceOf(Service::class, $serviceItem->getRelated());

        // Inventory item
        $inventoryItem = InvoiceItem::factory()->create([
            'event_invoice_id' => $this->invoice->id,
            'item_type' => 'inventory',
            'item_id' => $this->inventory->id,
        ]);
        $inventoryRelation = $inventoryItem->item();
        $this->assertInstanceOf(Inventory::class, $inventoryRelation->getRelated());

        // Custom item
        $customItem = InvoiceItem::factory()->create([
            'event_invoice_id' => $this->invoice->id,
            'item_type' => 'custom',
            'item_id' => null,
        ]);
        $this->assertNull($customItem->item());
    }

    /** @test */
    public function it_scopes_by_type()
    {
        InvoiceItem::factory()->create([
            'event_invoice_id' => $this->invoice->id,
            'item_type' => 'inventory',
        ]);

        InvoiceItem::factory()->create([
            'event_invoice_id' => $this->invoice->id,
            'item_type' => 'custom',
        ]);

        $serviceItems = InvoiceItem::services()->get();
        $inventoryItems = InvoiceItem::inventory()->get();
        $customItems = InvoiceItem::custom()->get();

        $this->assertCount(1, $serviceItems);
        $this->assertCount(1, $inventoryItems);
        $this->assertCount(1, $customItems);
    }

    /** @test */
    public function it_calculates_total_correctly()
    {
        $this->invoiceItem->calculateTotal();

        $subtotal = 2 * 100.00; // quantity * unit_price = 200.00
        $discountAmount = $subtotal * 0.10; // 10% discount = 20.00
        $taxableAmount = $subtotal - $discountAmount; // 200.00 - 20.00 = 180.00
        $taxAmount = $taxableAmount * 0.08; // 8% tax = 14.40
        $totalPrice = $subtotal - $discountAmount + $taxAmount; // 200.00 - 20.00 + 14.40 = 194.40

        $this->assertEquals(20.00, $this->invoiceItem->discount_amount);
        $this->assertEquals(14.40, $this->invoiceItem->tax_amount);
        $this->assertEquals(194.40, $this->invoiceItem->total_price);
    }

    /** @test */
    public function it_applies_discount()
    {
        $this->invoiceItem->applyDiscount(15.0);

        $this->assertEquals(15.0, $this->invoiceItem->discount_percentage);
        
        // Should recalculate totals
        $expectedDiscount = 200.00 * 0.15; // 30.00
        $this->assertEquals(30.00, $this->invoiceItem->discount_amount);
    }

    /** @test */
    public function it_applies_tax()
    {
        $this->invoiceItem->applyTax(0.10);

        $this->assertEquals(0.10, $this->invoiceItem->tax_rate);
        
        // Should recalculate totals
        $subtotal = 200.00;
        $discountAmount = 20.00; // 10% of 200
        $taxableAmount = 180.00; // 200 - 20
        $expectedTax = $taxableAmount * 0.10; // 18.00
        $this->assertEquals(18.00, $this->invoiceItem->tax_amount);
    }

    /** @test */
    public function it_calculates_subtotal()
    {
        $this->assertEquals(200.00, $this->invoiceItem->getSubtotal());
    }

    /** @test */
    public function it_calculates_discounted_subtotal()
    {
        $this->invoiceItem->update(['discount_amount' => 30.00]);
        $this->assertEquals(170.00, $this->invoiceItem->getDiscountedSubtotal());
    }

    /** @test */
    public function it_gets_taxable_amount()
    {
        $this->invoiceItem->update(['discount_amount' => 25.00]);
        $this->assertEquals(175.00, $this->invoiceItem->getTaxableAmount());
    }

    /** @test */
    public function it_checks_item_type()
    {
        $this->assertTrue($this->invoiceItem->isService());
        $this->assertFalse($this->invoiceItem->isInventory());
        $this->assertFalse($this->invoiceItem->isCustom());

        $inventoryItem = InvoiceItem::factory()->create([
            'event_invoice_id' => $this->invoice->id,
            'item_type' => 'inventory',
        ]);

        $this->assertTrue($inventoryItem->isInventory());
        $this->assertFalse($inventoryItem->isService());
        $this->assertFalse($inventoryItem->isCustom());
    }

    /** @test */
    public function it_checks_if_has_discount()
    {
        $this->assertTrue($this->invoiceItem->hasDiscount());

        $itemWithoutDiscount = InvoiceItem::factory()->create([
            'event_invoice_id' => $this->invoice->id,
            'discount_percentage' => 0,
            'discount_amount' => 0,
        ]);

        $this->assertFalse($itemWithoutDiscount->hasDiscount());
    }

    /** @test */
    public function it_checks_if_has_tax()
    {
        $this->assertTrue($this->invoiceItem->hasTax());

        $itemWithoutTax = InvoiceItem::factory()->create([
            'event_invoice_id' => $this->invoice->id,
            'tax_rate' => 0,
            'tax_amount' => 0,
        ]);

        $this->assertFalse($itemWithoutTax->hasTax());
    }

    /** @test */
    public function it_gets_formatted_description()
    {
        $this->invoiceItem->update([
            'description' => 'Test Service',
            'notes' => 'Special instructions',
        ]);

        $formatted = $this->invoiceItem->getFormattedDescription();
        $this->assertEquals("Test Service\nSpecial instructions", $formatted);
    }

    /** @test */
    public function it_gets_item_details_for_service()
    {
        $details = $this->invoiceItem->getItemDetails();

        $this->assertIsArray($details);
        $this->assertEquals($this->service->id, $details['id']);
        $this->assertEquals($this->service->service_name, $details['name']);
        $this->assertEquals($this->service->service_description, $details['description']);
    }

    /** @test */
    public function it_gets_item_details_for_inventory()
    {
        $inventoryItem = InvoiceItem::factory()->create([
            'event_invoice_id' => $this->invoice->id,
            'item_type' => 'inventory',
            'item_id' => $this->inventory->id,
        ]);

        $details = $inventoryItem->getItemDetails();

        $this->assertIsArray($details);
        $this->assertEquals($this->inventory->id, $details['id']);
        $this->assertEquals($this->inventory->inventory_name, $details['name']);
        $this->assertEquals($this->inventory->inventory_description, $details['description']);
    }

    /** @test */
    public function it_returns_null_details_for_custom_items()
    {
        $customItem = InvoiceItem::factory()->create([
            'event_invoice_id' => $this->invoice->id,
            'item_type' => 'custom',
            'item_id' => null,
        ]);

        $this->assertNull($customItem->getItemDetails());
    }

    /** @test */
    public function it_updates_quantity()
    {
        $this->invoiceItem->updateQuantity(5);

        $this->assertEquals(5, $this->invoiceItem->quantity);
        
        // Should recalculate totals
        $expectedSubtotal = 5 * 100.00; // 500.00
        $this->assertEquals($expectedSubtotal, $this->invoiceItem->getSubtotal());
    }

    /** @test */
    public function it_updates_unit_price()
    {
        $this->invoiceItem->updateUnitPrice(150.00);

        $this->assertEquals(150.00, $this->invoiceItem->unit_price);
        
        // Should recalculate totals
        $expectedSubtotal = 2 * 150.00; // 300.00
        $this->assertEquals($expectedSubtotal, $this->invoiceItem->getSubtotal());
    }

    /** @test */
    public function it_updates_description()
    {
        $this->invoiceItem->updateDescription('Updated Description');
        $this->assertEquals('Updated Description', $this->invoiceItem->description);
    }

    /** @test */
    public function it_adds_notes()
    {
        $this->invoiceItem->update(['notes' => 'Initial notes']);
        $this->invoiceItem->addNotes('Additional notes');

        $this->assertEquals("Initial notes\nAdditional notes", $this->invoiceItem->notes);
    }

    /** @test */
    public function it_sets_sort_order()
    {
        $this->invoiceItem->setSortOrder(5);
        $this->assertEquals(5, $this->invoiceItem->sort_order);
    }

    /** @test */
    public function it_duplicates_item()
    {
        $duplicate = $this->invoiceItem->duplicate();

        $this->assertInstanceOf(InvoiceItem::class, $duplicate);
        $this->assertNotEquals($this->invoiceItem->id, $duplicate->id);
        $this->assertEquals($this->invoiceItem->description, $duplicate->description);
        $this->assertEquals($this->invoiceItem->quantity, $duplicate->quantity);
        $this->assertEquals($this->invoiceItem->unit_price, $duplicate->unit_price);
    }

    /** @test */
    public function it_calculates_effective_discount_rate()
    {
        $this->invoiceItem->update([
            'quantity' => 2,
            'unit_price' => 100.00,
            'discount_amount' => 40.00,
        ]);

        $effectiveRate = $this->invoiceItem->getEffectiveDiscountRate();
        $this->assertEquals(20.0, $effectiveRate); // 40/200 * 100 = 20%
    }

    /** @test */
    public function it_calculates_effective_tax_rate()
    {
        $this->invoiceItem->update([
            'quantity' => 2,
            'unit_price' => 100.00,
            'discount_amount' => 20.00, // Subtotal: 200, Discounted: 180
            'tax_amount' => 18.00,
        ]);

        $effectiveRate = $this->invoiceItem->getEffectiveTaxRate();
        $this->assertEquals(10.0, $effectiveRate); // 18/180 * 100 = 10%
    }

    /** @test */
    public function it_formats_prices()
    {
        $this->invoiceItem->update([
            'unit_price' => 123.456,
            'total_price' => 246.912,
        ]);

        $this->assertEquals('123.46', $this->invoiceItem->getFormattedUnitPrice());
        $this->assertEquals('246.91', $this->invoiceItem->getFormattedTotalPrice());
    }

    /** @test */
    public function it_sets_default_sort_order_on_creation()
    {
        $item1 = InvoiceItem::create([
            'event_invoice_id' => $this->invoice->id,
            'item_type' => 'custom',
            'description' => 'Item 1',
            'quantity' => 1,
            'unit_price' => 100.00,
        ]);

        $item2 = InvoiceItem::create([
            'event_invoice_id' => $this->invoice->id,
            'item_type' => 'custom',
            'description' => 'Item 2',
            'quantity' => 1,
            'unit_price' => 100.00,
        ]);

        $this->assertGreaterThan($item1->sort_order, $item2->sort_order);
    }

    /** @test */
    public function it_updates_invoice_totals_when_item_changes()
    {
        $originalTotal = $this->invoice->total_amount;
        
        // Update item quantity
        $this->invoiceItem->updateQuantity(5);
        
        // Invoice totals should be recalculated
        $this->assertNotEquals($originalTotal, $this->invoice->fresh()->total_amount);
    }

    /** @test */
    public function it_updates_invoice_totals_when_item_is_deleted()
    {
        $originalTotal = $this->invoice->total_amount;
        
        $this->invoiceItem->delete();
        
        // Invoice totals should be recalculated
        $this->assertNotEquals($originalTotal, $this->invoice->fresh()->total_amount);
    }

    /** @test */
    public function it_handles_zero_amounts_gracefully()
    {
        $item = InvoiceItem::factory()->create([
            'event_invoice_id' => $this->invoice->id,
            'quantity' => 0,
            'unit_price' => 0,
        ]);

        $this->assertEquals(0, $item->getSubtotal());
        $this->assertEquals(0, $item->getEffectiveDiscountRate());
        $this->assertEquals(0, $item->getEffectiveTaxRate());
    }
}