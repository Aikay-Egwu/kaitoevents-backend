<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Client;
use App\Models\EventType;
use App\Models\Inventory;
use App\Models\InventoryCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests covering the EventInventoryController HTTP endpoints.
 *
 * Endpoints tested (admin/events/{event}/inventory):
 *   GET    /catalog  — active/business-owned filter, search/filter shape
 *   GET    /         — index assigned items + summary shape
 *   POST   /         — assign: validation, business-owned guard,
 *                      custom_price persistence, stock guard
 *   PUT    /{inv}    — update: 3-rule pricing state machine via HTTP
 *   DELETE /{inv}    — remove, cost_impact reporting
 *
 * Edge cases covered (Req 3 checklist):
 *   - inactive inventory assign rejected 422 NOT_BUSINESS_OWNED
 *   - zero / negative quantity rejected 422 VALIDATION_FAILED
 *   - quantity > stock rejected 422 QUANTITY_UNAVAILABLE
 *   - concurrent assignments over last stock collide safely 422
 *   - update with quantity change triggers price reset (catalogue) via HTTP
 *   - update with only custom_price preserves qty AND survives another read
 *   - update with BOTH qty + custom_price uses the new custom_price
 *   - assigning the same item twice returns ALREADY_ASSIGNED 422
 */
class EventInventoryEndpointTest extends TestCase
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

    private function createOwnedEvent(): Event
    {
        $client = Client::factory()->create();
        $eventType = EventType::factory()->create(['status' => 'active']);
        return Event::factory()->create([
            'client_id'     => $client->id,
            'event_type_id' => $eventType->id,
            'status'        => 'confirmed',
            'budget'        => 5000.00,
        ]);
    }

    private function createActiveInventory(
        float $price,
        int $stock,
        array $attrs = [],
    ): Inventory {
        $cat = InventoryCategory::factory()->create();
        return Inventory::factory()->active()->create(array_merge([
            'inventory_category_id' => $cat->id,
            'price'                => $price,
            'quantity_available'   => $stock,
            'total_quantity'       => max($stock, 1),
            'location_name'        => 'Warehouse A',
        ], $attrs));
    }

    // ==================================================================
    // Catalog endpoint
    // ==================================================================

    /** @test */
    public function catalog_excludes_already_assigned_and_can_filter_active_via_query_param()
    {
        $this->actingAsAdmin();
        $event = $this->createOwnedEvent();

        $active   = $this->createActiveInventory(50.00, 20);
        $inactive = Inventory::factory()->create(['is_active' => false, 'price' => 50]);
        $assigned = $this->createActiveInventory(75.00, 5);
        $event->assignInventory($assigned->id, 2);

        // Default behaviour (matches InventoryController.index): is_active filter
        // only applied when the caller explicitly asks for it, so all business-owned
        // inventory items (including retired/inactive) can be browsed together.
        $all = $this->getJson("/api/admin/events/{$event->id}/inventory/catalog");
        $all->assertOk();
        $allIds = collect($all->json('data'))->pluck('id')->all();
        $this->assertContains($active->id, $allIds, 'active item visible by default');
        $this->assertContains($inactive->id, $allIds, 'inactive item visible by default (no filter)');
        $this->assertNotContains($assigned->id, $allIds, 'already-assigned item always excluded');

        // When ?is_active=1 is explicitly sent we keep inactive items out — same
        // toggle behaviour used on the standalone inventory management page.
        $res = $this->getJson("/api/admin/events/{$event->id}/inventory/catalog?is_active=1");
        $res->assertOk();
        $this->assertTrue($res->json('success'));

        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($active->id, $ids, 'active item should be in catalog');
        $this->assertNotContains($inactive->id, $ids, 'inactive item hidden when is_active=1');
        $this->assertNotContains($assigned->id, $ids, 'already-assigned item must be excluded');

        // Shape check
        $first = $res->json('data.0');
        $this->assertArrayHasKey('price_raw', $first);
        $this->assertArrayHasKey('is_available', $first);
        $this->assertArrayHasKey('business_owned', $first);
        $this->assertTrue($first['business_owned']);
    }

    // ==================================================================
    // Index endpoint (assigned items + summary)
    // ==================================================================

    /** @test */
    public function index_lists_assigned_inventory_with_correct_summary_and_pivot_pricing()
    {
        $this->actingAsAdmin();
        $event = $this->createOwnedEvent();

        $a = $this->createActiveInventory(100.00, 10);
        $b = $this->createActiveInventory(200.00, 5);

        $event->assignInventory($a->id, 2, 90.00); // 2 × 90   = 180  (override)
        $event->assignInventory($b->id, 1);        // 1 × 200  = 200  (catalogue)

        $res = $this->getJson("/api/admin/events/{$event->id}/inventory");
        $res->assertOk();

        $this->assertCount(2, $res->json('data'));

        // Summary totals are pivot-derived (180 + 200 = 380, NOT catalogue 2×100 + 1×200 = 400)
        $this->assertEqualsWithDelta(380.00, $res->json('summary.total_cost'), 0.0001);
        // total_quantity = qty(A:2) + qty(B:1) = 3
        $this->assertEquals(3, $res->json('summary.total_quantity'));
        $this->assertEquals(1, $res->json('summary.price_overrides_count'));

        // Individual row: first assignment has correct price_override flag
        $rowA = collect($res->json('data'))->firstWhere('id', $a->id);
        $this->assertTrue($rowA['assignment']['price_override']);
        $this->assertEqualsWithDelta(90.00, $rowA['assignment']['unit_price_raw'], 0.0001);
        $this->assertEqualsWithDelta(180.00, $rowA['assignment']['total_price_raw'], 0.0001);
    }

    // ==================================================================
    // Assign endpoint — validation guards
    // ==================================================================

    /** @test */
    public function assign_zero_quantity_rejected_422()
    {
        $this->actingAsAdmin();
        $event = $this->createOwnedEvent();
        $inv = $this->createActiveInventory(50.00, 10);

        $res = $this->postJson("/api/admin/events/{$event->id}/inventory", [
            'inventory_id' => $inv->id,
            'quantity'     => 0,
        ]);

        $res->assertStatus(422);
        $this->assertFalse($res->json('success'));
        $this->assertEquals('VALIDATION_FAILED', $res->json('error.code'));
    }

    /** @test */
    public function assign_negative_quantity_rejected_422()
    {
        $this->actingAsAdmin();
        $event = $this->createOwnedEvent();
        $inv = $this->createActiveInventory(50.00, 10);

        $res = $this->postJson("/api/admin/events/{$event->id}/inventory", [
            'inventory_id' => $inv->id,
            'quantity'     => -5,
        ]);

        $res->assertStatus(422);
        $this->assertEquals('VALIDATION_FAILED', $res->json('error.code'));
    }

    /** @test */
    public function assign_inactive_inventory_rejected_422_NOT_BUSINESS_OWNED()
    {
        $this->actingAsAdmin();
        $event = $this->createOwnedEvent();
        $inv = Inventory::factory()->create([
            'is_active'          => false,
            'price'              => 40.00,
            'quantity_available' => 10,
        ]);

        $res = $this->postJson("/api/admin/events/{$event->id}/inventory", [
            'inventory_id' => $inv->id,
            'quantity'     => 2,
        ]);

        $res->assertStatus(422);
        $this->assertEquals('NOT_BUSINESS_OWNED', $res->json('error.code'));
    }

    /** @test */
    public function assign_same_item_twice_returns_ALREADY_ASSIGNED_422()
    {
        $this->actingAsAdmin();
        $event = $this->createOwnedEvent();
        $inv = $this->createActiveInventory(40.00, 20);
        $event->assignInventory($inv->id, 1);

        $res = $this->postJson("/api/admin/events/{$event->id}/inventory", [
            'inventory_id' => $inv->id,
            'quantity'     => 3,
        ]);

        $res->assertStatus(422);
        $this->assertEquals('ALREADY_ASSIGNED', $res->json('error.code'));
    }

    /** @test */
    public function assign_over_available_stock_returns_QUANTITY_UNAVAILABLE_422()
    {
        $this->actingAsAdmin();
        $event = $this->createOwnedEvent();
        $inv = $this->createActiveInventory(25.00, 5); // stock = 5

        $res = $this->postJson("/api/admin/events/{$event->id}/inventory", [
            'inventory_id' => $inv->id,
            'quantity'     => 20, // way over available
        ]);

        $res->assertStatus(422);
        $this->assertEquals('QUANTITY_UNAVAILABLE', $res->json('error.code'));
        $this->assertEquals(5, $res->json('error.available_quantity'));
    }

    // ==================================================================
    // Assign endpoint — custom_price persists correctly via HTTP
    // ==================================================================

    /** @test */
    public function assign_custom_price_persists_in_pivot_and_invoice_total()
    {
        $this->actingAsAdmin();
        $event = $this->createOwnedEvent();
        $inv = $this->createActiveInventory(100.00, 20); // catalogue = 100

        $res = $this->postJson("/api/admin/events/{$event->id}/inventory", [
            'inventory_id' => $inv->id,
            'quantity'     => 4,
            'custom_price' => 75.25, // manual override
        ]);

        $res->assertStatus(201);
        $this->assertTrue($res->json('success'));
        $this->assertTrue($res->json('data.assignment.price_override'));
        $this->assertEqualsWithDelta(
            75.25 * 4,
            $res->json('data.assignment.total_price_raw'),
            0.0001,
        );

        // And the Event-level total (same figure as what the invoice will use)
        $event->load('inventories');
        $this->assertEqualsWithDelta(75.25 * 4, $event->getInventoriesTotal(), 0.0001);
    }

    // ==================================================================
    // Update endpoint — 3-rule pricing state machine via HTTP
    // ==================================================================

    /** @test */
    public function update_quantity_change_triggers_price_reset_to_catalogue()
    {
        $this->actingAsAdmin();
        $event = $this->createOwnedEvent();
        $inv = $this->createActiveInventory(120.00, 10); // catalogue 120

        // Initial assignment with custom override (×3 @ 80) = 240
        $event->assignInventory($inv->id, 3, 80.00);

        $res = $this->putJson("/api/admin/events/{$event->id}/inventory/{$inv->id}", [
            'quantity' => 6, // qty changed, NO custom_price → rule 1, reset to 120
        ]);

        $res->assertOk();
        $this->assertTrue($res->json('success'));
        $this->assertTrue(
            $res->json('data.assignment.was_reset'),
            'was_reset flag should be true when qty changes without custom_price'
        );
        $this->assertEqualsWithDelta(120.00, $res->json('data.assignment.unit_price_raw'), 0.0001);
        $this->assertEqualsWithDelta(120.00 * 6, $res->json('data.assignment.total_price_raw'), 0.0001);
    }

    /** @test */
    public function update_only_custom_price_preserves_original_quantity()
    {
        $this->actingAsAdmin();
        $event = $this->createOwnedEvent();
        $inv = $this->createActiveInventory(60.00, 10);

        $event->assignInventory($inv->id, 5); // qty=5, unit=60

        $res = $this->putJson("/api/admin/events/{$event->id}/inventory/{$inv->id}", [
            'custom_price' => 55.00,
        ]);

        $res->assertOk();
        $this->assertFalse($res->json('data.assignment.was_reset'));
        $this->assertEquals(5, $res->json('data.assignment.quantity'));
        $this->assertEqualsWithDelta(55.00, $res->json('data.assignment.unit_price_raw'), 0.0001);

        // And a follow-up GET confirms the override survives reads.
        $index = $this->getJson("/api/admin/events/{$event->id}/inventory");
        $row = collect($index->json('data'))->firstWhere('id', $inv->id);
        $this->assertTrue($row['assignment']['price_override']);
        $this->assertEqualsWithDelta(55.00, $row['assignment']['unit_price_raw'], 0.0001);
    }

    /** @test */
    public function update_both_quantity_and_custom_price_uses_new_custom_price()
    {
        $this->actingAsAdmin();
        $event = $this->createOwnedEvent();
        $inv = $this->createActiveInventory(200.00, 20);

        $event->assignInventory($inv->id, 2, 180.00); // qty=2 @ 180

        $res = $this->putJson("/api/admin/events/{$event->id}/inventory/{$inv->id}", [
            'quantity'     => 8,
            'custom_price' => 160.00,
        ]);

        $res->assertOk();
        $this->assertFalse($res->json('data.assignment.was_reset'));
        $this->assertEquals(8, $res->json('data.assignment.quantity'));
        $this->assertEqualsWithDelta(160.00, $res->json('data.assignment.unit_price_raw'), 0.0001);
        $this->assertEqualsWithDelta(1280.00, $res->json('data.assignment.total_price_raw'), 0.0001);
    }

    /** @test */
    public function update_unassigned_item_returns_NOT_ASSIGNED_422()
    {
        $this->actingAsAdmin();
        $event = $this->createOwnedEvent();
        $inv = $this->createActiveInventory(50.00, 5);

        $res = $this->putJson("/api/admin/events/{$event->id}/inventory/{$inv->id}", [
            'quantity' => 2,
        ]);

        $res->assertStatus(422);
        $this->assertEquals('NOT_ASSIGNED', $res->json('error.code'));
    }

    // ==================================================================
    // Remove endpoint
    // ==================================================================

    /** @test */
    public function remove_detaches_inventory_and_reduces_event_total()
    {
        $this->actingAsAdmin();
        $event = $this->createOwnedEvent();
        $inv = $this->createActiveInventory(50.00, 10);
        $event->assignInventory($inv->id, 4); // 200

        $before = $event->fresh()->getInventoriesTotal();
        $this->assertEqualsWithDelta(200.00, $before, 0.0001);

        $res = $this->deleteJson("/api/admin/events/{$event->id}/inventory/{$inv->id}");

        $res->assertOk();
        $this->assertTrue($res->json('success'));

        // Controller wraps cost impact inside 'cost_impact' envelope.
        $this->assertEquals(
            number_format(200.00, 2),
            $res->json('data.cost_impact.cost_reduction'),
            'Removed items report correct formatted cost reduction.'
        );
        $this->assertEquals(0, $event->fresh()->inventories()->count());
    }

    // ==================================================================
    // Req 3 (e) — stock consistency: external stock changes + concurrent
    // quantity increases are guarded. Since Event.assignInventory does not
    // itself decrement the catalogue quantity_available (stock bookkeeping
    // is done elsewhere in the real app via UsageLog/Reservations), we
    // simulate a real-world race: between Request A and Request B an
    // external stock edit reduces quantity_available to 0 → Request B
    // must fail with QUANTITY_UNAVAILABLE instead of double-booking.
    // ==================================================================

    /** @test */
    public function assign_fails_when_external_stock_reduces_below_requested_qty()
    {
        $this->actingAsAdmin();
        $event = $this->createOwnedEvent();

        // 2 units in catalogue; assign 2 for event A (consumes visible stock)
        $inv = $this->createActiveInventory(300.00, 2);

        // Simulate another external process (e.g. reservation editor)
        // drops quantity_available to ZERO before our HTTP request runs.
        $inv->quantity_available = 0;
        $inv->save();

        $res = $this->postJson("/api/admin/events/{$event->id}/inventory", [
            'inventory_id' => $inv->id,
            'quantity'     => 1,
        ]);

        $res->assertStatus(422);
        $this->assertEquals('QUANTITY_UNAVAILABLE', $res->json('error.code'));
        $this->assertEquals(0, $res->json('error.available_quantity'));
    }

    /** @test */
    public function update_increase_qty_over_remaining_stock_fails_QUANTITY_UNAVAILABLE()
    {
        $this->actingAsAdmin();
        $event = $this->createOwnedEvent();
        // Only 3 units in catalogue total
        $inv = $this->createActiveInventory(25.00, 3);

        // Initially book 2 (still 1 left in stock)
        $event->assignInventory($inv->id, 2);

        // Try to bump qty from 2 → 6 (need 4 more, only 1 free)
        $res = $this->putJson("/api/admin/events/{$event->id}/inventory/{$inv->id}", [
            'quantity' => 6,
        ]);

        $res->assertStatus(422);
        $this->assertEquals('QUANTITY_UNAVAILABLE', $res->json('error.code'));
    }

    // ==================================================================
    // Cross-smoke: Event.getEstimatedTotal() matches services + inv
    // ==================================================================

    /** @test */
    public function event_get_estimated_total_matches_services_plus_inventories_with_pivot_pricing()
    {
        $this->actingAsAdmin();
        $event = $this->createOwnedEvent();

        $inv = $this->createActiveInventory(50.00, 10);
        $event->assignInventory($inv->id, 2, 40.00); // 2 × 40 = 80  (override)

        $service = \App\Models\Service::factory()->create(['price' => 150]);
        $event->services()->attach($service->id, [
            'quantity' => 3,
            'price'    => 170,
        ]); // 3 × 170 = 510

        $this->assertEqualsWithDelta(
            80 + 510,
            $event->fresh()->getEstimatedTotal(),
            0.0001,
        );
    }
}
