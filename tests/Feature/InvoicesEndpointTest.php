<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Client;
use App\Models\EventInvoice;
use App\Models\Service;
use App\Models\Inventory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests covering the Invoices section admin endpoints.
 *
 * Validates:
 *  - Requirement 2: events are rendered in chronological order
 *    (event_date DESC, most recent first) from GET admin/invoices/events
 *  - Requirement 3: clicking an event row loads the corresponding
 *    invoice (verified by fetching GET admin/invoices/{id} and
 *    confirming it matches the clicked event)
 *  - Requirement 4: the totals line up with the attached services
 *    and inventory for the event.
 */
class InvoicesEndpointTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        return $this;
    }

    /**
     * Creates a set of events (each with a generated invoice) with
     * deliberately varied event dates so we can assert sort order.
     *
     * @return Event[] Events indexed 0..n in creation order, dates are
     *                 intentionally NOT chronological (to verify sorting).
     */
    private function seedEventsWithInvoices(): array
    {
        $client = Client::factory()->create();

        // Dates deliberately NOT in chronological order on insert.
        $dates = [
            '2024-01-15',   // oldest (3rd)
            '2024-06-20',   // newest (1st)
            '2024-03-10',   // middle (2nd)
        ];

        $events = [];
        foreach ($dates as $date) {
            $event = Event::factory()->create([
                'client_id'  => $client->id,
                'event_date' => $date,
                'event_name' => "Event on {$date}",
            ]);
            EventInvoice::factory()->create([
                'event_id'            => $event->id,
                'total_amount'        => 1000,
                'subtotal'            => 1000,
                'tax_amount'          => 0,
                'discount_amount'     => 0,
                'payment_status'      => 'pending',
            ]);
            $events[] = $event;
        }
        return $events;
    }

    // ------------------------------------------------------------------
    // Requirement 2: Chronological event sort order (newest first)
    // ------------------------------------------------------------------

    /** @test */
    public function events_with_invoices_are_sorted_newest_event_date_first()
    {
        $this->actingAsAdmin();
        $this->seedEventsWithInvoices();

        $response = $this->getJson('/api/admin/invoices/events');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'event_id',
                    'event_name',
                    'event_date',
                    'invoice' => ['id', 'invoice_number', 'total_amount'],
                ],
            ],
            'meta' => ['pagination', 'summary'],
        ]);

        $this->assertTrue($response->json('success'));

        $returned = $response->json('data');
        $this->assertCount(3, $returned);

        // Most recent -> oldest expected dates
        $expectedOrder = ['2024-06-20', '2024-03-10', '2024-01-15'];
        $actualOrder = array_column($returned, 'event_date');
        $this->assertEquals($expectedOrder, $actualOrder);
    }

    /** @test */
    public function empty_events_list_returns_success_with_empty_data()
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/admin/invoices/events');

        $response->assertOk();
        $this->assertTrue($response->json('success'));
        $this->assertEmpty($response->json('data'));
        $this->assertEquals(0, $response->json('meta.summary.total_events'));
    }

    // ------------------------------------------------------------------
    // Requirement 3: Clicking an event loads the correct invoice
    // (verified here at the API layer by hitting the show endpoint
    // and confirming event <-> invoice linkage).
    // ------------------------------------------------------------------

    /** @test */
    public function show_invoice_endpoint_returns_line_items_and_event()
    {
        $this->actingAsAdmin();

        $client = Client::factory()->create();
        $event = Event::factory()->create([
            'client_id'  => $client->id,
            'event_date' => '2024-05-01',
            'event_name' => 'Grand Ballroom Gala',
        ]);

        // Seed services & inventory attached to the event.
        $service = Service::factory()->create(['price' => 200.00]);
        $inventory = Inventory::factory()->create(['price' => 50.00]);
        $event->services()->attach($service->id, [
            'quantity' => 1,
            'price' => 200.00,
        ]);
        // Pivot NOT NULL price required — pass the inventory price explicitly.
        $event->inventories()->attach($inventory->id, [
            'quantity' => 4,
            'price'    => 50.00,
        ]);

        // Build the invoice and populate its line items using the
        // existing model helper methods (this is what clicking "view
        // invoice" on the frontend will hydrate from the DB).
        $invoice = EventInvoice::factory()->create([
            'event_id' => $event->id,
            'tax_rate' => 0.20,
            'discount_percentage' => 0,
            'subtotal' => 400.00,
            'tax_amount' => 80.00,
            'discount_amount' => 0,
            'total_amount' => 480.00,
            'payment_status' => 'pending',
        ]);
        $invoice->addServiceItems();
        $invoice->addInventoryItems();
        $invoice->calculateTotals();

        $response = $this->getJson("/api/admin/invoices/{$invoice->id}");

        $response->assertOk();
        $this->assertTrue($response->json('success'));

        // Event link is correct → confirms we loaded the invoice for
        // the clicked event, not some other row.
        $this->assertEquals($event->id, $response->json('data.event.id'));
        $this->assertEquals('Grand Ballroom Gala', $response->json('data.event.name'));

        // Line items contain both service and inventory categories.
        $this->assertNotEmpty($response->json('data.items'));
        $this->assertGreaterThanOrEqual(2, $response->json('data.items_summary.total_items'));
        $this->assertEquals(1, $response->json('data.items_summary.service_items'));
        $this->assertEquals(1, $response->json('data.items_summary.inventory_items'));
    }

    // ------------------------------------------------------------------
    // Requirement 4 + integration: totals agree with the line items.
    // ------------------------------------------------------------------

    /** @test */
    public function invoice_show_endpoint_totals_match_line_items_sum()
    {
        $this->actingAsAdmin();

        $client = Client::factory()->create();
        $event = Event::factory()->create(['client_id' => $client->id]);

        $service = Service::factory()->create(['price' => 150.00]);
        $inventory = Inventory::factory()->create(['price' => 75.00]);

        // 3 * services + 2 * inventories = 450 + 150 = 600 subtotal
        $event->services()->attach($service->id, [
            'quantity' => 3,
            'price' => 150.00,
        ]);
        $event->inventories()->attach($inventory->id, [
            'quantity' => 2,
            'price'    => 75.00,
        ]);

        $invoice = EventInvoice::factory()->create([
            'event_id' => $event->id,
            'tax_rate' => 0,
            'discount_percentage' => 0,
        ]);
        $invoice->addServiceItems();
        $invoice->addInventoryItems();
        $invoice->calculateTotals();

        $response = $this->getJson("/api/admin/invoices/{$invoice->id}");

        $response->assertOk();

        $rawSubtotal = (float) $response->json('data.amounts.subtotal_raw');
        $rawTotal = (float) $response->json('data.amounts.total_amount_raw');

        // 150 * 3 + 75 * 2 = 600
        $this->assertEqualsWithDelta(600.0, $rawSubtotal, 0.01);
        $this->assertEqualsWithDelta(600.0, $rawTotal, 0.01);
    }

    // ------------------------------------------------------------------
    // Summary stats (meta.summary block) correctness
    // ------------------------------------------------------------------

    /** @test */
    public function summary_stats_reflect_pending_and_paid_counts()
    {
        $this->actingAsAdmin();
        $client = Client::factory()->create();

        $paidEvent = Event::factory()->create([
            'client_id' => $client->id,
            'event_date' => '2024-06-01',
        ]);
        EventInvoice::factory()->create([
            'event_id' => $paidEvent->id,
            'payment_status' => 'paid',
            'total_amount' => 500,
        ]);

        $pendingEvent = Event::factory()->create([
            'client_id' => $client->id,
            'event_date' => '2024-06-15',
        ]);
        EventInvoice::factory()->create([
            'event_id' => $pendingEvent->id,
            'payment_status' => 'pending',
            'total_amount' => 300,
        ]);

        $response = $this->getJson('/api/admin/invoices/events');

        $response->assertOk();
        $summary = $response->json('meta.summary');

        $this->assertEquals(2, $summary['total_events']);
        $this->assertEqualsWithDelta(800.0, (float) $summary['total_revenue'], 0.01);
        $this->assertEquals(1, $summary['paid_invoices']);
        $this->assertEquals(1, $summary['pending_invoices']);
    }
}
