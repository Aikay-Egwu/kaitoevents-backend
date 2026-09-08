<?php

namespace Tests\Unit\Models;

use App\Models\EventInvoice;
use App\Models\InvoiceItem;
use App\Models\Event;
use App\Models\Client;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected $invoice;
    protected $event;

    protected function setUp(): void
    {
        parent::setUp();
        
        $client = Client::factory()->create();
        $eventType = EventType::factory()->create();
        $user = User::factory()->create();
        
        $this->event = Event::factory()->create([
            'client_id' => $client->id,
            'event_type_id' => $eventType->id,
        ]);

        $this->invoice = EventInvoice::factory()->create([
            'event_id' => $this->event->id,
            'subtotal' => 1000.00,
            'tax_rate' => 0.08,
            'discount_percentage' => 10.00,
            'payment_status' => 'pending',
            'generated_by' => $user->id,
        ]);
    }

    /** @test */
    public function it_has_correct_fillable_attributes()
    {
        $fillable = [
            'event_id', 'invoice_number', 'subtotal', 'tax_amount', 'discount_amount',
            'total_amount', 'payment_status', 'payment_method', 'payment_date',
            'generated_at', 'due_date', 'notes', 'terms_and_conditions', 'tax_rate',
            'discount_percentage', 'currency', 'invoice_status', 'sent_at', 'viewed_at',
            'generated_by', 'approved_by', 'approved_at'
        ];

        $invoice = new EventInvoice();
        $this->assertEquals($fillable, $invoice->getFillable());
    }

    /** @test */
    public function it_casts_attributes_correctly()
    {
        $invoice = EventInvoice::factory()->create([
            'subtotal' => 123.456,
            'tax_amount' => 9.876,
            'tax_rate' => 0.0875,
            'generated_at' => '2024-01-15 10:30:00',
            'due_date' => '2024-02-15',
        ]);

        $this->assertEquals('123.46', $invoice->subtotal);
        $this->assertEquals('9.88', $invoice->tax_amount);
        $this->assertEquals('0.0875', $invoice->tax_rate);
        $this->assertInstanceOf(\Carbon\Carbon::class, $invoice->generated_at);
        $this->assertInstanceOf(\Carbon\Carbon::class, $invoice->due_date);
    }

    /** @test */
    public function it_belongs_to_event()
    {
        $this->assertInstanceOf(Event::class, $this->invoice->event);
        $this->assertEquals($this->event->id, $this->invoice->event->id);
    }

    /** @test */
    public function it_has_many_items()
    {
        InvoiceItem::factory()->count(3)->create([
            'event_invoice_id' => $this->invoice->id,
        ]);

        $this->assertCount(3, $this->invoice->items);
        $this->assertInstanceOf(InvoiceItem::class, $this->invoice->items->first());
    }

    /** @test */
    public function it_belongs_to_generated_by_user()
    {
        $this->assertInstanceOf(User::class, $this->invoice->generatedBy);
        $this->assertEquals($this->invoice->generated_by, $this->invoice->generatedBy->id);
    }

    /** @test */
    public function it_scopes_paid_invoices()
    {
        EventInvoice::factory()->create(['payment_status' => 'paid']);
        EventInvoice::factory()->create(['payment_status' => 'pending']);

        $paidInvoices = EventInvoice::paid()->get();
        
        $this->assertCount(1, $paidInvoices);
        $this->assertEquals('paid', $paidInvoices->first()->payment_status);
    }

    /** @test */
    public function it_scopes_pending_invoices()
    {
        EventInvoice::factory()->create(['payment_status' => 'paid']);
        
        $pendingInvoices = EventInvoice::pending()->get();
        
        $this->assertCount(1, $pendingInvoices);
        $this->assertEquals('pending', $pendingInvoices->first()->payment_status);
    }

    /** @test */
    public function it_scopes_overdue_invoices()
    {
        EventInvoice::factory()->create([
            'payment_status' => 'pending',
            'due_date' => now()->subDays(5),
        ]);

        EventInvoice::factory()->create([
            'payment_status' => 'pending',
            'due_date' => now()->addDays(5),
        ]);

        $overdueInvoices = EventInvoice::overdue()->get();
        
        $this->assertCount(1, $overdueInvoices);
    }

    /** @test */
    public function it_generates_invoice_number()
    {
        $invoice = EventInvoice::factory()->create(['invoice_number' => null]);
        $invoiceNumber = $invoice->generateInvoiceNumber();

        $this->assertNotNull($invoiceNumber);
        $this->assertStringStartsWith('INV-', $invoiceNumber);
        $this->assertEquals($invoiceNumber, $invoice->fresh()->invoice_number);
    }

    /** @test */
    public function it_calculates_totals_correctly()
    {
        // Create invoice items
        InvoiceItem::factory()->create([
            'event_invoice_id' => $this->invoice->id,
            'quantity' => 2,
            'unit_price' => 100.00,
            'total_price' => 200.00,
        ]);

        InvoiceItem::factory()->create([
            'event_invoice_id' => $this->invoice->id,
            'quantity' => 1,
            'unit_price' => 300.00,
            'total_price' => 300.00,
        ]);

        $this->invoice->calculateTotals();

        $this->assertEquals(500.00, $this->invoice->subtotal);
        $this->assertEquals(50.00, $this->invoice->discount_amount); // 10% of 500
        $this->assertEquals(36.00, $this->invoice->tax_amount); // 8% of (500-50)
        $this->assertEquals(486.00, $this->invoice->total_amount); // 500 - 50 + 36
    }

    /** @test */
    public function it_adds_service_items()
    {
        $service = \App\Models\Service::factory()->create(['price' => 250.00]);
        $this->event->services()->attach($service->id, [
            'quantity' => 2,
            'price' => 200.00,
        ]);

        $this->invoice->addServiceItems();

        $this->assertCount(1, $this->invoice->items);
        $item = $this->invoice->items->first();
        $this->assertEquals('service', $item->item_type);
        $this->assertEquals($service->id, $item->item_id);
        $this->assertEquals(2, $item->quantity);
        $this->assertEquals(200.00, $item->unit_price);
        $this->assertEquals(400.00, $item->total_price);
    }

    /** @test */
    public function it_adds_inventory_items()
    {
        $inventory = \App\Models\Inventory::factory()->create(['rental_price' => 50.00]);
        $this->event->inventories()->attach($inventory->id, [
            'quantity' => 3,
        ]);

        $this->invoice->addInventoryItems();

        $this->assertCount(1, $this->invoice->items);
        $item = $this->invoice->items->first();
        $this->assertEquals('inventory', $item->item_type);
        $this->assertEquals($inventory->id, $item->item_id);
        $this->assertEquals(3, $item->quantity);
        $this->assertEquals(50.00, $item->unit_price);
        $this->assertEquals(150.00, $item->total_price);
    }

    /** @test */
    public function it_adds_custom_items()
    {
        $customItem = $this->invoice->addCustomItem('Custom Service', 2, 75.00);

        $this->assertInstanceOf(InvoiceItem::class, $customItem);
        $this->assertEquals('custom', $customItem->item_type);
        $this->assertNull($customItem->item_id);
        $this->assertEquals('Custom Service', $customItem->description);
        $this->assertEquals(2, $customItem->quantity);
        $this->assertEquals(75.00, $customItem->unit_price);
        $this->assertEquals(150.00, $customItem->total_price);
    }

    /** @test */
    public function it_checks_payment_status()
    {
        $this->assertTrue($this->invoice->isPending());
        $this->assertFalse($this->invoice->isPaid());

        $this->invoice->update(['payment_status' => 'paid']);
        $this->assertTrue($this->invoice->isPaid());
        $this->assertFalse($this->invoice->isPending());
    }

    /** @test */
    public function it_checks_if_overdue()
    {
        $this->assertFalse($this->invoice->isOverdue());

        $this->invoice->update(['due_date' => now()->subDays(5)]);
        $this->assertTrue($this->invoice->isOverdue());

        $this->invoice->update(['payment_status' => 'paid']);
        $this->assertFalse($this->invoice->isOverdue()); // Paid invoices are not overdue
    }

    /** @test */
    public function it_calculates_days_until_due()
    {
        $this->invoice->update(['due_date' => now()->addDays(10)]);
        $this->assertEquals(10, $this->invoice->getDaysUntilDue());

        $this->invoice->update(['due_date' => now()->subDays(5)]);
        $this->assertEquals(-5, $this->invoice->getDaysUntilDue());
    }

    /** @test */
    public function it_calculates_days_overdue()
    {
        $this->assertEquals(0, $this->invoice->getDaysOverdue());

        $this->invoice->update(['due_date' => now()->subDays(7)]);
        $this->assertEquals(7, $this->invoice->getDaysOverdue());
    }

    /** @test */
    public function it_gets_amount_due()
    {
        $this->invoice->update(['total_amount' => 500.00]);
        $this->assertEquals(500.00, $this->invoice->getAmountDue());

        $this->invoice->update(['payment_status' => 'paid']);
        $this->assertEquals(0, $this->invoice->getAmountDue());
    }

    /** @test */
    public function it_calculates_discounted_subtotal()
    {
        $this->invoice->update([
            'subtotal' => 1000.00,
            'discount_amount' => 100.00,
        ]);

        $this->assertEquals(900.00, $this->invoice->getDiscountedSubtotal());
    }

    /** @test */
    public function it_marks_as_paid()
    {
        $this->invoice->markAsPaid('credit_card');

        $this->assertEquals('paid', $this->invoice->payment_status);
        $this->assertEquals('credit_card', $this->invoice->payment_method);
        $this->assertNotNull($this->invoice->payment_date);
    }

    /** @test */
    public function it_marks_as_cancelled()
    {
        $this->invoice->markAsCancelled('Client requested cancellation');

        $this->assertEquals('cancelled', $this->invoice->payment_status);
        $this->assertStringContainsString('Client requested cancellation', $this->invoice->notes);
    }

    /** @test */
    public function it_sends_to_client()
    {
        $this->invoice->sendToClient();

        $this->assertEquals('sent', $this->invoice->invoice_status);
        $this->assertNotNull($this->invoice->sent_at);
    }

    /** @test */
    public function it_marks_as_viewed()
    {
        $this->assertNull($this->invoice->viewed_at);
        
        $this->invoice->markAsViewed();
        
        $this->assertNotNull($this->invoice->viewed_at);
        
        // Should not update if already viewed
        $firstViewedAt = $this->invoice->viewed_at;
        $this->invoice->markAsViewed();
        $this->assertEquals($firstViewedAt, $this->invoice->viewed_at);
    }

    /** @test */
    public function it_approves_invoice()
    {
        $user = User::factory()->create();
        
        $this->invoice->approve($user->id);

        $this->assertEquals($user->id, $this->invoice->approved_by);
        $this->assertNotNull($this->invoice->approved_at);
        $this->assertEquals('approved', $this->invoice->invoice_status);
    }

    /** @test */
    public function it_gets_formatted_invoice_number()
    {
        $this->invoice->update(['invoice_number' => 'INV-2024-001']);
        $this->assertEquals('INV-2024-001', $this->invoice->getFormattedInvoiceNumber());

        $this->invoice->update(['invoice_number' => null]);
        $this->assertEquals('DRAFT', $this->invoice->getFormattedInvoiceNumber());
    }

    /** @test */
    public function it_gets_status_color_and_label()
    {
        $this->invoice->update(['payment_status' => 'paid']);
        $this->assertEquals('green', $this->invoice->getStatusColor());
        $this->assertEquals('Paid', $this->invoice->getStatusLabel());

        $this->invoice->update(['payment_status' => 'pending']);
        $this->assertEquals('yellow', $this->invoice->getStatusColor());
        $this->assertEquals('Pending', $this->invoice->getStatusLabel());

        $this->invoice->update(['payment_status' => 'overdue']);
        $this->assertEquals('red', $this->invoice->getStatusColor());
        $this->assertEquals('Overdue', $this->invoice->getStatusLabel());
    }

    /** @test */
    public function it_checks_if_can_be_edited()
    {
        $this->invoice->update(['invoice_status' => 'draft', 'payment_status' => 'pending']);
        $this->assertTrue($this->invoice->canBeEdited());

        $this->invoice->update(['payment_status' => 'paid']);
        $this->assertFalse($this->invoice->canBeEdited());

        $this->invoice->update(['invoice_status' => 'sent', 'payment_status' => 'pending']);
        $this->assertFalse($this->invoice->canBeEdited());
    }

    /** @test */
    public function it_checks_if_can_be_cancelled()
    {
        $this->assertTrue($this->invoice->canBeCancelled());

        $this->invoice->update(['payment_status' => 'paid']);
        $this->assertFalse($this->invoice->canBeCancelled());

        $this->invoice->update(['payment_status' => 'cancelled']);
        $this->assertFalse($this->invoice->canBeCancelled());
    }

    /** @test */
    public function it_checks_if_can_be_refunded()
    {
        $this->assertFalse($this->invoice->canBeRefunded());

        $this->invoice->update(['payment_status' => 'paid']);
        $this->assertTrue($this->invoice->canBeRefunded());
    }

    /** @test */
    public function it_gets_items_by_type()
    {
        InvoiceItem::factory()->create([
            'event_invoice_id' => $this->invoice->id,
            'item_type' => 'service',
        ]);

        InvoiceItem::factory()->count(2)->create([
            'event_invoice_id' => $this->invoice->id,
            'item_type' => 'inventory',
        ]);

        $serviceItems = $this->invoice->getServiceItems();
        $inventoryItems = $this->invoice->getInventoryItems();

        $this->assertCount(1, $serviceItems);
        $this->assertCount(2, $inventoryItems);
        $this->assertEquals('service', $serviceItems->first()->item_type);
        $this->assertEquals('inventory', $inventoryItems->first()->item_type);
    }

    /** @test */
    public function it_gets_total_by_type()
    {
        InvoiceItem::factory()->create([
            'event_invoice_id' => $this->invoice->id,
            'item_type' => 'service',
            'total_price' => 200.00,
        ]);

        InvoiceItem::factory()->create([
            'event_invoice_id' => $this->invoice->id,
            'item_type' => 'service',
            'total_price' => 300.00,
        ]);

        InvoiceItem::factory()->create([
            'event_invoice_id' => $this->invoice->id,
            'item_type' => 'inventory',
            'total_price' => 150.00,
        ]);

        $this->assertEquals(500.00, $this->invoice->getServiceTotal());
        $this->assertEquals(150.00, $this->invoice->getInventoryTotal());
    }

    /** @test */
    public function it_sets_default_values_on_creation()
    {
        $invoice = EventInvoice::create([
            'event_id' => $this->event->id,
        ]);

        $this->assertEquals('USD', $invoice->currency);
        $this->assertEquals('draft', $invoice->invoice_status);
        $this->assertEquals('pending', $invoice->payment_status);
        $this->assertNotNull($invoice->generated_at);
        $this->assertNotNull($invoice->due_date);
    }

    /** @test */
    public function it_generates_invoice_number_after_creation()
    {
        $invoice = EventInvoice::create([
            'event_id' => $this->event->id,
        ]);

        $this->assertNotNull($invoice->fresh()->invoice_number);
        $this->assertStringStartsWith('INV-', $invoice->fresh()->invoice_number);
    }
}