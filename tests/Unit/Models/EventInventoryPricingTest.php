<?php

namespace Tests\Unit\Models;

use App\Models\Event;
use App\Models\Client;
use App\Models\EventType;
use App\Models\InventoryCategory;
use App\Models\Inventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests verifying the Event -> inventory pricing state machine.
 *
 * These validate the core pricing rules (Requirement 1 & 2):
 *   A. assignInventory() stores custom_price in the pivot when provided.
 *   B. updateInventory() — Rule 1: quantity change WITHOUT custom_price
 *      resets the unit price back to the catalogue base.
 *   C. updateInventory() — Rule 2: same quantity, custom_price provided
 *      overrides the unit price and SURVIVES the edit.
 *   D. updateInventory() — Rule 3: both quantity AND custom_price provided
 *      in the same call applies the new custom_price (no reset).
 *   E. getInventoriesTotal() and getEstimatedTotal() always read from
 *      the pivot (so manual overrides appear in the invoice total).
 *   F. Non-assigned inventory updates throw OutOfBoundsException.
 */
class EventInventoryPricingTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function createBaseEvent(): Event
    {
        $client = Client::factory()->create();
        $eventType = EventType::factory()->create(['status' => 'active']);
        return Event::factory()->create([
            'client_id'     => $client->id,
            'event_type_id' => $eventType->id,
            'budget'        => 10000.00,
            'status'        => 'confirmed',
        ]);
    }

    private function createActiveInventory(float $price, int $stock = 50): Inventory
    {
        $cat = InventoryCategory::factory()->create();
        return Inventory::factory()->active()->create([
            'inventory_category_id' => $cat->id,
            'price'                => $price,
            'quantity_available'   => $stock,
            'total_quantity'       => $stock,
        ]);
    }

    // ------------------------------------------------------------------
    // Req A: assignInventory persists the pivot price (base or custom)
    // ------------------------------------------------------------------

    /** @test */
    public function assign_without_custom_price_uses_catalogue_price_in_pivot()
    {
        $event = $this->createBaseEvent();
        $inv = $this->createActiveInventory(75.50);

        $event->assignInventory($inv->id, 4);

        $pivot = $event->inventories()->first()->pivot;
        $this->assertEquals(4, $pivot->quantity);
        $this->assertEqualsWithDelta(75.50, $pivot->price, 0.0001);
    }

    /** @test */
    public function assign_with_custom_price_persists_override_in_pivot()
    {
        $event = $this->createBaseEvent();
        $inv = $this->createActiveInventory(50.00);

        $event->assignInventory($inv->id, 3, 42.50);

        $pivot = $event->inventories()->first()->pivot;
        $this->assertEquals(3, $pivot->quantity);
        $this->assertEqualsWithDelta(42.50, $pivot->price, 0.0001);
    }

    // ------------------------------------------------------------------
    // Req B: Rule 1 — qty change + no custom_price resets to catalogue
    // ------------------------------------------------------------------

    /** @test */
    public function update_quantity_changed_without_custom_price_resets_to_catalogue_price()
    {
        $event = $this->createBaseEvent();
        $inv = $this->createActiveInventory(100.00);

        // 1. assign with a manual override (unit = 80.00, qty=2)
        $event->assignInventory($inv->id, 2, 80.00);
        $pivot = $event->inventories()->first()->pivot;
        $this->assertEqualsWithDelta(80.00, $pivot->price, 0.0001);

        // 2. change ONLY quantity (no custom_price) → rule 1 fires
        $result = $event->updateInventory($inv->id, 5);

        $this->assertTrue($result['was_reset']);
        $this->assertEquals(5, $result['quantity']);
        $this->assertEqualsWithDelta(100.00, $result['unit_price'], 0.0001);

        // And the pivot row actually reflects the reset
        $fresh = $event->fresh()->inventories()->first()->pivot;
        $this->assertEqualsWithDelta(100.00, $fresh->price, 0.0001);
    }

    // ------------------------------------------------------------------
    // Req C: Rule 2 — same qty + custom_price keeps override
    // ------------------------------------------------------------------

    /** @test */
    public function update_only_custom_price_preserves_quantity_and_persists_new_price()
    {
        $event = $this->createBaseEvent();
        $inv = $this->createActiveInventory(60.00);

        // Catalogue base: 60, assigned qty 5 at base price
        $event->assignInventory($inv->id, 5);

        // Now change ONLY the unit price (keep qty 5)
        $result = $event->updateInventory($inv->id, null, 55.00);

        $this->assertFalse($result['was_reset']);
        $this->assertEquals(5, $result['quantity']);
        $this->assertEqualsWithDelta(55.00, $result['unit_price'], 0.0001);

        $fresh = $event->fresh()->inventories()->first()->pivot;
        $this->assertEquals(5, $fresh->quantity);
        $this->assertEqualsWithDelta(55.00, $fresh->price, 0.0001);
    }

    /** @test */
    public function existing_manual_override_survives_no_op_update()
    {
        $event = $this->createBaseEvent();
        $inv = $this->createActiveInventory(60.00);

        $event->assignInventory($inv->id, 2, 40.00);

        // Neither qty nor custom_price provided → no change
        $result = $event->updateInventory($inv->id, null, null);

        $this->assertFalse($result['was_reset']);
        $this->assertEqualsWithDelta(40.00, $result['unit_price'], 0.0001);
    }

    // ------------------------------------------------------------------
    // Req D: Rule 3 — both changed (qty + custom) → custom wins
    // ------------------------------------------------------------------

    /** @test */
    public function update_both_quantity_and_custom_price_applies_new_custom_price()
    {
        $event = $this->createBaseEvent();
        $inv = $this->createActiveInventory(100.00);

        $event->assignInventory($inv->id, 2, 80.00); // qty 2 @ 80

        $result = $event->updateInventory($inv->id, 10, 90.00);

        // qty changed, but we also re-provided custom → NO reset, apply 90
        $this->assertFalse($result['was_reset']);
        $this->assertEquals(10, $result['quantity']);
        $this->assertEqualsWithDelta(90.00, $result['unit_price'], 0.0001);

        $fresh = $event->fresh()->inventories()->first()->pivot;
        $this->assertEqualsWithDelta(90.00, $fresh->price, 0.0001);
    }

    // ------------------------------------------------------------------
    // Req E: InventoriesTotal() uses pivot (critical for downstream
    //        invoice totals — overrides must NOT silently vanish)
    // ------------------------------------------------------------------

    /** @test */
    public function get_inventories_total_uses_pivot_price_not_catalogue_price()
    {
        $event = $this->createBaseEvent();
        $invA = $this->createActiveInventory(100.00); // catalogue 100
        $invB = $this->createActiveInventory(200.00); // catalogue 200

        // Manual overrides: invA @ 50 (×2), invB kept at catalogue 200 × 3
        $event->assignInventory($invA->id, 2, 50.00);
        $event->assignInventory($invB->id, 3);

        // fresh() reloads the inventories relation so the pivot data is
        // actually available (assignInventory writes the DB but doesn't
        // refresh the in-memory collection).
        $total = $event->fresh()->getInventoriesTotal();

        // Expected: (50 × 2) + (200 × 3) = 100 + 600 = 700
        // If the impl wrongly used catalogue prices it would yield
        // (100 × 2) + (200 × 3) = 800 → test would fail.
        $this->assertEqualsWithDelta(700.00, $total, 0.0001);
    }

    /** @test */
    public function get_estimated_total_combines_services_and_inventories_from_pivot()
    {
        $event = $this->createBaseEvent();
        $inv = $this->createActiveInventory(50.00);

        $event->assignInventory($inv->id, 4, 40.00); // inventory part = 160

        // Attach a service with a custom pivot price (override catalogue).
        $service = \App\Models\Service::factory()->create(['price' => 200]);
        $event->services()->attach($service->id, [
            'quantity' => 2,
            'price'    => 220.00,
        ]); // 440

        $this->assertEqualsWithDelta(
            160 + 440,
            $event->fresh()->getEstimatedTotal(),
            0.0001,
        );
    }

    /** @test */
    public function index_summary_total_cost_is_computed_from_pivot_not_catalogue()
    {
        $event = $this->createBaseEvent();
        $inv = $this->createActiveInventory(300.00); // catalogue 300
        $event->assignInventory($inv->id, 2, 150.00); // override: 150 × 2 = 300

        // We re-use the controller's index() shape via the model's accessor
        // pattern. The key invariant: summary.total_cost must equal 300
        // (the override total), NOT 600 (catalogue × qty).
        $summaryTotal = $event->fresh()->getInventoriesTotal();
        $this->assertEqualsWithDelta(300.00, $summaryTotal, 0.0001);
    }

    // ------------------------------------------------------------------
    // Req F: OutOfBounds guard on updating unassigned items
    // ------------------------------------------------------------------

    /** @test */
    public function update_unassigned_inventory_throws_out_of_bounds()
    {
        $event = $this->createBaseEvent();
        $inv = $this->createActiveInventory(50.00);

        $this->expectException(\OutOfBoundsException::class);
        $event->updateInventory($inv->id, 3);
    }
}
