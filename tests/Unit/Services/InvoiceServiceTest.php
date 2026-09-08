<?php

namespace Tests\Unit\Services;

use App\Services\InvoiceService;
use App\Models\Event;
use App\Models\EventInvoice;
use App\Models\Client;
use App\Models\EventType;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Inventory;
use App\Models\InfentoryCategory;
use App\Models\User;
use App\Http\Requests\GenerateInvoiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class InvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $invoiceService;
    protected $event;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->invoiceService = new InvoiceService();
        $this->user = User::factory()->create();
        
        $client = Client::factory()->create();
        $eventType = EventType::factory()->create();
        
        $this->event = Event::factory()->create([
            'client_id' => $client->id,
            'event_type_id' => $eventType->id,
            'status' => 'confirmed',
        ]);

        // Add services and inventory to event
        $serviceCategory = ServiceCategory::factory()->create();
        $service = Service::factory()->create([
            'service_category_id' => $serviceCategory->id,
            'price' => 500.00,
        ]);

        $inventoryCategory = InfentoryCategory::factory()->create();
        $inventory = Inventory::factory()->create([
            'inventory_category_id' => $inventoryCategory->id,
            'rental_price' => 100.00,
        ]);

        $this->event->services()->attach($service->id, [
            'quantity' => 2,
            'price' => 450.00,
        ]);

        $this->event->inventories()->attach($inventory->id, [
            'quantity' => 3,
        ]);

        Auth::login($this->user);
    }

    /** @test */
    public function it_generates_invoice_for_event()
    {
        $request = $this->createMockRequest([
            'tax_rate' => 0.08,
            'discount_percentage' => 10.0,
            'include_services' => true,
            'include_inventory' => true,
        ]);

        $invoice = $this->invoiceService->generateInvoice($this->event, $request);

        $this->assertInstanceOf(EventInvoice::class, $invoice);
        $this->assertEquals($this->event->id, $invoice->event_id);
        $this->assertEquals($this->user->id, $invoice->generated_by);
        $this->assertNotNull($invoice->invoice_number);
        $this->assertEquals(0.08, $invoice->tax_rate);
        $this->assertEquals(10.0, $invoice->discount_percentage);
    }

    /** @test */
    public function it_adds_service_items_to_invoice()
    {
        $request = $this->createMockRequest([
            'include_services' => true,
            'include_inventory' => false,
        ]);

        $invoice = $this->invoiceService->generateInvoice($this->event, $request);

        $serviceItems = $invoice->getServiceItems();
        $this->assertCount(1, $serviceItems);
        
        $serviceItem = $serviceItems->first();
        $this->assertEquals('service', $serviceItem->item_type);
        $this->assertEquals(2, $serviceItem->quantity);
        $this->assertEquals(450.00, $serviceItem->unit_price);
        $this->assertEquals(900.00, $serviceItem->total_price);
    }

    /** @test */
    public function it_adds_inventory_items_to_invoice()
    {
        $request = $this->createMockRequest([
            'include_services' => false,
            'include_inventory' => true,
        ]);

        $invoice = $this->invoiceService->generateInvoice($this->event, $request);

        $inventoryItems = $invoice->getInventoryItems();
        $this->assertCount(1, $inventoryItems);
        
        $inventoryItem = $inventoryItems->first();
        $this->assertEquals('inventory', $inventoryItem->item_type);
        $this->assertEquals(3, $inventoryItem->quantity);
        $this->assertEquals(100.00, $inventoryItem->unit_price);
        $this->assertEquals(300.00, $inventoryItem->total_price);
    }

    /** @test */
    public function it_adds_custom_items_to_invoice()
    {
        $request = $this->createMockRequest([
            'include_services' => false,
            'include_inventory' => false,
            'custom_items' => [
                [
                    'description' => 'Custom Service',
                    'quantity' => 2,
                    'unit_price' => 150.00,
                    'notes' => 'Special requirements',
                ],
            ],
        ]);

        $invoice = $this->invoiceService->generateInvoice($this->event, $request);

        $customItems = $invoice->getCustomItems();
        $this->assertCount(1, $customItems);
        
        $customItem = $customItems->first();
        $this->assertEquals('custom', $customItem->item_type);
        $this->assertEquals('Custom Service', $customItem->description);
        $this->assertEquals(2, $customItem->quantity);
        $this->assertEquals(150.00, $customItem->unit_price);
        $this->assertEquals(300.00, $customItem->total_price);
        $this->assertEquals('Special requirements', $customItem->notes);
    }

    /** @test */
    public function it_calculates_invoice_totals_correctly()
    {
        $request = $this->createMockRequest([
            'tax_rate' => 0.08,
            'discount_percentage' => 10.0,
            'include_services' => true,
            'include_inventory' => true,
        ]);

        $invoice = $this->invoiceService->generateInvoice($this->event, $request);

        // Services: 2 * 450 = 900
        // Inventory: 3 * 100 = 300
        // Subtotal: 1200
        // Discount: 1200 * 0.10 = 120
        // Taxable: 1200 - 120 = 1080
        // Tax: 1080 * 0.08 = 86.40
        // Total: 1200 - 120 + 86.40 = 1166.40

        $this->assertEquals(1200.00, $invoice->subtotal);
        $this->assertEquals(120.00, $invoice->discount_amount);
        $this->assertEquals(86.40, $invoice->tax_amount);
        $this->assertEquals(1166.40, $invoice->total_amount);
    }

    /** @test */
    public function it_prevents_generating_invoice_for_event_with_existing_invoice()
    {
        // Create existing invoice
        EventInvoice::factory()->create(['event_id' => $this->event->id]);

        $request = $this->createMockRequest();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Event already has an invoice generated.');

        $this->invoiceService->generateInvoice($this->event, $request);
    }

    /** @test */
    public function it_prevents_generating_invoice_for_invalid_event_status()
    {
        $this->event->update(['status' => 'pending']);

        $request = $this->createMockRequest();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invoice can only be generated for confirmed, in-progress, or completed events.');

        $this->invoiceService->generateInvoice($this->event, $request);
    }

    /** @test */
    public function it_prevents_generating_invoice_for_event_without_billable_items()
    {
        // Remove services and inventory
        $this->event->services()->detach();
        $this->event->inventories()->detach();

        $request = $this->createMockRequest();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Event must have services or inventory to generate an invoice.');

        $this->invoiceService->generateInvoice($this->event, $request);
    }

    /** @test */
    public function it_regenerates_existing_invoice()
    {
        $invoice = EventInvoice::factory()->create([
            'event_id' => $this->event->id,
            'invoice_status' => 'draft',
            'payment_status' => 'pending',
        ]);

        $request = $this->createMockRequest([
            'tax_rate' => 0.10, // Different from original
            'discount_percentage' => 15.0,
        ]);

        $regeneratedInvoice = $this->invoiceService->regenerateInvoice($invoice, $request);

        $this->assertEquals($invoice->id, $regeneratedInvoice->id);
        $this->assertEquals(0.10, $regeneratedInvoice->tax_rate);
        $this->assertEquals(15.0, $regeneratedInvoice->discount_percentage);
    }

    /** @test */
    public function it_prevents_regenerating_non_editable_invoice()
    {
        $invoice = EventInvoice::factory()->create([
            'event_id' => $this->event->id,
            'payment_status' => 'paid',
        ]);

        $request = $this->createMockRequest();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invoice cannot be regenerated in its current state.');

        $this->invoiceService->regenerateInvoice($invoice, $request);
    }

    /** @test */
    public function it_calculates_detailed_invoice_totals()
    {
        $invoice = EventInvoice::factory()->create([
            'event_id' => $this->event->id,
            'tax_rate' => 0.08,
            'discount_percentage' => 10.0,
        ]);

        // Add items manually for testing
        $invoice->items()->create([
            'item_type' => 'service',
            'description' => 'Test Service',
            'quantity' => 2,
            'unit_price' => 100.00,
            'total_price' => 200.00,
        ]);

        $breakdown = $this->invoiceService->calculateInvoiceTotals($invoice);

        $this->assertArrayHasKey('items', $breakdown);
        $this->assertArrayHasKey('subtotals', $breakdown);
        $this->assertArrayHasKey('discount', $breakdown);
        $this->assertArrayHasKey('tax', $breakdown);
        $this->assertArrayHasKey('totals', $breakdown);

        $this->assertCount(1, $breakdown['items']);
        $this->assertEquals(200.00, $breakdown['subtotals']['total']);
        $this->assertEquals(20.00, $breakdown['discount']['amount']); // 10% of 200
        $this->assertEquals(14.40, $breakdown['tax']['amount']); // 8% of (200-20)
        $this->assertEquals(194.40, $breakdown['totals']['total_amount']); // 200 - 20 + 14.40
    }

    /** @test */
    public function it_generates_unique_invoice_numbers()
    {
        $invoiceNumber1 = $this->invoiceService->generateInvoiceNumber();
        $invoiceNumber2 = $this->invoiceService->generateInvoiceNumber();

        $this->assertNotEquals($invoiceNumber1, $invoiceNumber2);
        $this->assertStringStartsWith('INV-', $invoiceNumber1);
        $this->assertStringStartsWith('INV-', $invoiceNumber2);
        $this->assertMatchesRegularExpression('/^INV-\d{6}-\d{4}$/', $invoiceNumber1);
    }

    /** @test */
    public function it_calculates_estimated_total_for_event()
    {
        $options = [
            'include_services' => true,
            'include_inventory' => true,
            'custom_items' => [
                ['quantity' => 1, 'unit_price' => 200.00],
            ],
            'tax_rate' => 0.08,
            'discount_percentage' => 10.0,
        ];

        $breakdown = $this->invoiceService->calculateEstimatedTotal($this->event, $options);

        // Services: 2 * 450 = 900
        // Inventory: 3 * 100 = 300
        // Custom: 1 * 200 = 200
        // Subtotal: 1400
        // Discount: 1400 * 0.10 = 140
        // Taxable: 1400 - 140 = 1260
        // Tax: 1260 * 0.08 = 100.80
        // Total: 1400 - 140 + 100.80 = 1360.80

        $this->assertEquals(900.00, $breakdown['services']);
        $this->assertEquals(300.00, $breakdown['inventory']);
        $this->assertEquals(200.00, $breakdown['custom']);
        $this->assertEquals(1400.00, $breakdown['subtotal']);
        $this->assertEquals(140.00, $breakdown['discount_amount']);
        $this->assertEquals(1260.00, $breakdown['taxable_amount']);
        $this->assertEquals(100.80, $breakdown['tax_amount']);
        $this->assertEquals(1360.80, $breakdown['total_amount']);
    }

    /** @test */
    public function it_gets_invoice_statistics()
    {
        // Create test invoices
        EventInvoice::factory()->create([
            'payment_status' => 'paid',
            'total_amount' => 1000.00,
            'generated_at' => now(),
        ]);

        EventInvoice::factory()->create([
            'payment_status' => 'pending',
            'total_amount' => 500.00,
            'generated_at' => now(),
        ]);

        EventInvoice::factory()->create([
            'payment_status' => 'pending',
            'total_amount' => 750.00,
            'due_date' => now()->subDays(5), // Overdue
            'generated_at' => now(),
        ]);

        $stats = $this->invoiceService->getInvoiceStatistics();

        $this->assertEquals(3, $stats['total_invoices']);
        $this->assertEquals(2250.00, $stats['total_amount']);
        $this->assertEquals(1, $stats['paid_invoices']);
        $this->assertEquals(1000.00, $stats['paid_amount']);
        $this->assertEquals(2, $stats['pending_invoices']);
        $this->assertEquals(1250.00, $stats['pending_amount']);
        $this->assertEquals(1, $stats['overdue_invoices']);
        $this->assertEquals(750.00, $stats['overdue_amount']);
        $this->assertEquals(750.00, $stats['average_invoice_amount']);
        $this->assertEquals(33.33, round($stats['payment_rate'], 2));
    }

    /** @test */
    public function it_processes_full_payment()
    {
        $invoice = EventInvoice::factory()->create([
            'total_amount' => 1000.00,
            'payment_status' => 'pending',
        ]);

        $paymentData = [
            'payment_method' => 'credit_card',
            'amount' => 1000.00,
            'notes' => 'Paid in full',
        ];

        $paidInvoice = $this->invoiceService->processPayment($invoice, $paymentData);

        $this->assertEquals('paid', $paidInvoice->payment_status);
        $this->assertEquals('credit_card', $paidInvoice->payment_method);
        $this->assertNotNull($paidInvoice->payment_date);
        $this->assertStringContainsString('Paid in full', $paidInvoice->notes);
    }

    /** @test */
    public function it_processes_partial_payment()
    {
        $invoice = EventInvoice::factory()->create([
            'total_amount' => 1000.00,
            'payment_status' => 'pending',
        ]);

        $paymentData = [
            'payment_method' => 'bank_transfer',
            'amount' => 500.00,
            'notes' => 'Partial payment',
        ];

        $partiallyPaidInvoice = $this->invoiceService->processPayment($invoice, $paymentData);

        $this->assertEquals('partially_paid', $partiallyPaidInvoice->payment_status);
        $this->assertEquals('bank_transfer', $partiallyPaidInvoice->payment_method);
        $this->assertStringContainsString('Partial payment', $partiallyPaidInvoice->notes);
    }

    /** @test */
    public function it_sends_invoice_to_client()
    {
        $invoice = EventInvoice::factory()->create([
            'event_id' => $this->event->id,
            'invoice_status' => 'draft',
        ]);

        $result = $this->invoiceService->sendInvoiceToClient($invoice);

        $this->assertTrue($result);
        $this->assertEquals('sent', $invoice->fresh()->invoice_status);
        $this->assertNotNull($invoice->fresh()->sent_at);
    }

    /** @test */
    public function it_handles_post_generation_actions()
    {
        $request = $this->createMockRequest([
            'auto_approve' => true,
            'send_to_client' => true,
        ]);

        $invoice = $this->invoiceService->generateInvoice($this->event, $request);

        $this->assertEquals('approved', $invoice->invoice_status);
        $this->assertNotNull($invoice->approved_at);
        $this->assertNotNull($invoice->sent_at);
    }

    /** @test */
    public function it_sets_correct_sort_order_for_items()
    {
        $request = $this->createMockRequest([
            'include_services' => true,
            'include_inventory' => true,
            'custom_items' => [
                ['description' => 'Custom Item', 'quantity' => 1, 'unit_price' => 100.00],
            ],
        ]);

        $invoice = $this->invoiceService->generateInvoice($this->event, $request);

        $items = $invoice->items()->ordered()->get();
        
        $this->assertCount(3, $items);
        $this->assertEquals(1, $items[0]->sort_order);
        $this->assertEquals(2, $items[1]->sort_order);
        $this->assertEquals(3, $items[2]->sort_order);
    }

    /**
     * Create a mock GenerateInvoiceRequest for testing.
     */
    private function createMockRequest(array $data = []): GenerateInvoiceRequest
    {
        $defaultData = [
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'tax_rate' => 0.08,
            'discount_percentage' => 0,
            'currency' => 'USD',
            'notes' => null,
            'terms_and_conditions' => 'Payment due within 30 days',
            'include_services' => true,
            'include_inventory' => true,
            'send_to_client' => false,
            'auto_approve' => false,
        ];

        $requestData = array_merge($defaultData, $data);

        $request = \Mockery::mock(GenerateInvoiceRequest::class);
        $request->shouldReceive('getValidatedDataWithDefaults')->andReturn($requestData);

        return $request;
    }
}