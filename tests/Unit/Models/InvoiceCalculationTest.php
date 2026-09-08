<?php

namespace Tests\Unit\Models;

use App\Models\Event;
use App\Models\Client;
use App\Models\EventType;
use App\Models\InventoryCategory;
use App\Models\Service;
use App\Models\Inventory;
use App\Models\EventInvoice;
use App\Models\InvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests verifying EventInvoice total-amount calculations
 * across services, inventory items, discount, and tax.
 *
 * Validates requirement 4: "Calculate and display the invoice's
 * total amount based on the sum of all selected services and
 * inventory items associated with the event, ensuring accurate
 * cost aggregation."
 */
class InvoiceCalculationTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Helpers — explicit attribute creation (no factories)
    // ------------------------------------------------------------------

    private function createClient(array $attrs = []): Client
    {
        return Client::create(array_merge([
            'firstname' => 'Jane',
            'lastname'  => 'Doe',
            'email'     => 'jane-' . uniqid() . '@example.com',
            'phone'     => '07123 456789',
        ], $attrs));
    }

    private function createEventWithClient(array $eventAttrs = []): Event
    {
        $client = $this->createClient();
        $eventType = EventType::create([
            'title'  => 'Wedding',
            'slug'   => 'wedding-' . uniqid(),
            'status' => 'active',
        ]);
        return Event::create(array_merge([
            'client_id'         => $client->id,
            'event_type_id'     => $eventType->id,
            'event_name'        => 'Test Event',
            'event_date'        => '2024-06-01',
            'start_time'        => '14:00:00',
            'end_time'          => '20:00:00',
            'number_of_guests'  => 50,
            'budget'            => 5000.00,
            'status'            => 'confirmed',
        ], $eventAttrs));
    }

    /**
     * Helper to create an EventInvoice record with a unique invoice_number
     * (which has a NOT NULL + unique constraint on the table).
     */
    private function createInvoiceForEvent(Event $event, array $attrs = []): EventInvoice
    {
        static $seq = 1;
        return EventInvoice::create(array_merge([
            'event_id'            => $event->id,
            'invoice_number'      => 'INV-TEST-' . date('Ymd') . '-' . ($seq++),
            'tax_rate'            => 0,
            'discount_percentage' => 0,
            'subtotal'            => 0,
            'tax_amount'          => 0,
            'discount_amount'     => 0,
            'total_amount'        => 0,
            'payment_status'      => 'pending',
            'invoice_status'      => 'draft',
            'generated_at'        => now(),
            'due_date'            => now()->addDays(30),
            'currency'            => 'GBP',
        ], $attrs));
    }

    // ------------------------------------------------------------------
    // Line-item total aggregation
    // ------------------------------------------------------------------

    /** @test */
    public function it_sums_service_and_inventory_line_items_for_subtotal()
    {
        $event = $this->createEventWithClient();
        $invoice = $this->createInvoiceForEvent($event);

        // Services: (100 * 2) + (200 * 1) = 400
        InvoiceItem::create([
            'event_invoice_id' => $invoice->id,
            'item_type'        => 'service',
            'description'      => 'Catering',
            'quantity'         => 2,
            'unit_price'       => 100.00,
            'total_price'      => 200.00,
        ]);
        InvoiceItem::create([
            'event_invoice_id' => $invoice->id,
            'item_type'        => 'service',
            'description'      => 'Photography',
            'quantity'         => 1,
            'unit_price'       => 200.00,
            'total_price'      => 200.00,
        ]);

        // Inventory: (50 * 3) + (75 * 2) = 300
        InvoiceItem::create([
            'event_invoice_id' => $invoice->id,
            'item_type'        => 'inventory',
            'description'      => 'Round Tables',
            'quantity'         => 3,
            'unit_price'       => 50.00,
            'total_price'      => 150.00,
        ]);
        InvoiceItem::create([
            'event_invoice_id' => $invoice->id,
            'item_type'        => 'inventory',
            'description'      => 'Chair Covers',
            'quantity'         => 2,
            'unit_price'       => 75.00,
            'total_price'      => 150.00,
        ]);

        $invoice->refresh()->calculateTotals();

        $this->assertEqualsWithDelta(700.00, $invoice->subtotal, 0.01);
        $this->assertEqualsWithDelta(700.00, $invoice->total_amount, 0.01);
        $this->assertEquals(0, $invoice->discount_amount);
        $this->assertEquals(0, $invoice->tax_amount);
    }

    // ------------------------------------------------------------------
    // Discount handling
    // ------------------------------------------------------------------

    /** @test */
    public function it_applies_percentage_discount_before_calculating_total()
    {
        $event = $this->createEventWithClient();
        $invoice = $this->createInvoiceForEvent($event, [
            'discount_percentage' => 10, // 10% off
        ]);

        InvoiceItem::create([
            'event_invoice_id' => $invoice->id,
            'item_type'        => 'service',
            'description'      => 'Venue Hire',
            'quantity'         => 1,
            'unit_price'       => 500.00,
            'total_price'      => 500.00,
        ]);

        $invoice->refresh()->calculateTotals();

        // Subtotal = 500, Discount = 50, Taxable = 450, Tax = 0, Total = 450
        $this->assertEqualsWithDelta(500.00, $invoice->subtotal, 0.01);
        $this->assertEqualsWithDelta(50.00, $invoice->discount_amount, 0.01);
        $this->assertEqualsWithDelta(450.00, $invoice->total_amount, 0.01);
    }

    // ------------------------------------------------------------------
    // Tax handling
    // ------------------------------------------------------------------

    /** @test */
    public function it_calculates_tax_on_the_discounted_amount()
    {
        $event = $this->createEventWithClient();
        $invoice = $this->createInvoiceForEvent($event, [
            'tax_rate'            => 0.20,   // 20% VAT
            'discount_percentage' => 10,     // 10% off
        ]);

        InvoiceItem::create([
            'event_invoice_id' => $invoice->id,
            'item_type'        => 'service',
            'description'      => 'Full Package',
            'quantity'         => 1,
            'unit_price'       => 1000.00,
            'total_price'      => 1000.00,
        ]);

        $invoice->refresh()->calculateTotals();

        // Subtotal 1000 -> 10% discount (100) -> taxable = 900 -> 20% tax = 180 -> total = 1080
        $this->assertEqualsWithDelta(1000.00, $invoice->subtotal, 0.01);
        $this->assertEqualsWithDelta(100.00, $invoice->discount_amount, 0.01);
        $this->assertEqualsWithDelta(180.00, $invoice->tax_amount, 0.01);
        $this->assertEqualsWithDelta(1080.00, $invoice->total_amount, 0.01);
    }

    // ------------------------------------------------------------------
    // Empty / edge-case handling (data validation guards)
    // ------------------------------------------------------------------

    /** @test */
    public function it_returns_zero_totals_when_there_are_no_line_items()
    {
        $event = $this->createEventWithClient();
        $invoice = $this->createInvoiceForEvent($event, [
            'tax_rate'            => 0.20,
            'discount_percentage' => 10,
        ]);

        $invoice->calculateTotals();

        $this->assertEqualsWithDelta(0.0, $invoice->subtotal, 0.01);
        $this->assertEqualsWithDelta(0.0, $invoice->total_amount, 0.01);
        $this->assertEqualsWithDelta(0.0, $invoice->tax_amount, 0.01);
    }

    /** @test */
    public function service_total_and_inventory_total_helpers_are_correct()
    {
        $event = $this->createEventWithClient();
        $invoice = $this->createInvoiceForEvent($event);

        InvoiceItem::create([
            'event_invoice_id' => $invoice->id,
            'item_type'        => 'service',
            'description'      => 'Music',
            'quantity'         => 1,
            'unit_price'       => 120,
            'total_price'      => 120,
        ]);
        InvoiceItem::create([
            'event_invoice_id' => $invoice->id,
            'item_type'        => 'inventory',
            'description'      => 'LED Uplighters',
            'quantity'         => 2,
            'unit_price'       => 40,
            'total_price'      => 80,
        ]);

        $invoice->calculateTotals();
        $invoice->load('items');

        $this->assertEqualsWithDelta(120.0, $invoice->getServiceTotal(), 0.01);
        $this->assertEqualsWithDelta(80.0, $invoice->getInventoryTotal(), 0.01);
        $this->assertEqualsWithDelta(200.0, $invoice->total_amount, 0.01);
    }

    /** @test */
    public function invoice_line_item_calculateTotal_handles_zero_quantity_and_price()
    {
        $event = $this->createEventWithClient();
        $invoice = $this->createInvoiceForEvent($event);

        // Zero-quantity line item should stay at zero and not produce NaN
        $item = InvoiceItem::create([
            'event_invoice_id' => $invoice->id,
            'item_type'        => 'custom',
            'description'      => 'Empty item',
            'quantity'         => 0,
            'unit_price'       => 0,
            'total_price'      => 0,
        ]);

        $result = $item->calculateTotal();

        $this->assertNotEquals(NAN, $result->total_price);
        $this->assertEqualsWithDelta(0.0, $result->total_price, 0.01);
    }

    // ------------------------------------------------------------------
    // Event::getEstimatedTotal mirrors invoice totals
    // ------------------------------------------------------------------

    /** @test */
    public function event_estimated_total_matches_line_items_sum()
    {
        $event = $this->createEventWithClient();

        // Service has fillable: name / price / description
        $service = Service::create([
            'name'  => 'Coordination',
            'price' => 150.00,
        ]);
        // Inventory requires: inventory_category_id, cost_price, price, name
        $cat = InventoryCategory::create([
            'category_name' => 'Linens',
            'category_description' => 'Table linens and drapes',
        ]);
        $inventory = Inventory::create([
            'name'                 => 'Table Linen',
            'inventory_category_id' => $cat->id,
            'price'                => 80.00,
            'cost_price'           => 30.00,
            'quantity_available'   => 10,
            'total_quantity'       => 20,
            'is_active'            => true,
        ]);

        $event->services()->attach($service->id, [
            'quantity' => 2,
            'price'    => 150.00,
        ]);
        // Pivot requires NOT NULL price column — provide inventory price explicitly.
        $event->inventories()->attach($inventory->id, [
            'quantity' => 3,
            'price'    => 80.00,
        ]);

        $event->load(['services', 'inventories']);
        $estimated = $event->getEstimatedTotal();

        // Services: 150 * 2 = 300; Inventory: 80 * 3 = 240; Total = 540
        $this->assertEqualsWithDelta(540.0, $estimated, 0.01);
    }
}
