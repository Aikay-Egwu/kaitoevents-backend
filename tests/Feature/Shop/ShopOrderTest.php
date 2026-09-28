<?php

namespace Tests\Feature\Shop;

// ShopOrderTest — T6 verification:
//  R-7  : valid payload → 201 + KE-YYYYMMDD-XXXX ref + correct totals; invalid totals → 422 TOTAL_MISMATCH
//  R-7  : item must reference an active, for_shop inventory row; unit price must match shop_price
//  R-8  : GET ref+email → 200; ref+wrong_email → 404 (no enumeration); GET ref without email → 400
//  R-12 : throttle:30,1 — 31st POST → 429 Too Many Attempts
//  U-1  : validation messages for invalid phone/postcode/empty items match project style
//
// Order items now reference `inventories.id` via `items.*.inventory_id`.

use App\Models\Inventory;
use App\Models\ShopOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Cache\RateLimiter;
use Tests\TestCase;

class ShopOrderTest extends TestCase
{
    use RefreshDatabase;

    protected Inventory $p1;
    protected Inventory $p2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p1 = Inventory::factory()->forShop(100.00)->create([
            'name'      => 'Package 1',
            'slug'      => 'p1',
            'is_active' => true,
        ]);
        $this->p2 = Inventory::factory()->forShop(50.50)->create([
            'name'      => 'Package 2',
            'slug'      => 'p2',
            'is_active' => true,
        ]);
        // Ensure rate limiter state is clean per test.
        $this->app->make(RateLimiter::class)->clear($this->rateLimitKey());
    }

    private function rateLimitKey(string $prefix = ''): string
    {
        return sha1('shop-order-post-127.0.0.1' . $prefix);
    }

    /**
     * Build valid order payload with recomputed totals for p1×2 + p2×1 → subtotal 250.50,
     * VAT 50.10, shipping 9.99, grand 310.59.
     */
    private function validPayload(array $overrides = []): array
    {
        $items = [
            [
                'inventory_id'      => $this->p1->id,
                'quantity'          => 2,
                'unit_price_ex_vat' => 100.00,
                'customisations'    => [[
                    'option_key' => 'opt-size', 'value' => 'Classic', 'label' => 'Classic Package',
                    'price_delta_inc_vat' => 540.00,
                ]],
            ],
            [
                'inventory_id'      => $this->p2->id,
                'quantity'          => 1,
                'unit_price_ex_vat' => 50.50,
            ],
        ];
        $shipping = 9.99;
        $totals   = ShopOrder::recomputeTotalsFromItems($items, $shipping);

        return array_merge([
            'customer_full_name'     => 'Toby Allsopp',
            'customer_email'         => 'toby@example.com',
            'customer_phone'         => '+44 20 7946 0958',
            'billing_address_line_1' => '10 Downing Street',
            'billing_address_line_2' => 'Westminster',
            'billing_city'           => 'London',
            'billing_postcode'       => 'SW1A 2AA',
            'billing_country'        => 'GB',
            'event_date'             => now()->addDays(20)->toDateString(),
            'event_venue'            => 'The Grand Hall',
            'payment_method'         => 'card',
            'shipping_amount'        => $shipping,
            'subtotal_ex_vat'        => $totals['subtotal_ex_vat'],
            'vat_amount'             => $totals['vat_amount'],
            'grand_total'            => $totals['grand_total'],
            'notes'                  => 'Please deliver before 10am.',
            'source_ref'             => 'shop-checkout',
            'items'                  => $items,
        ], $overrides);
    }

    /** @test */
    public function creating_valid_order_returns_201_with_ke_reference_and_correct_db_row()
    {
        $res = $this->postJson('/api/public/shop/orders', $this->validPayload());
        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.customer_email', 'toby@example.com')
            ->assertJsonPath('data.status', ShopOrder::STATUS_AWAITING_PAYMENT)
            ->assertJsonPath('data.grand_total_raw', 310.59)
            ->assertJsonStructure(['expires_at']);

        $ref = $res->json('data.order_ref');
        $this->assertMatchesRegularExpression('/^KE-\d{8}-[A-Z0-9]{4}$/', $ref);

        // Confirm DB row exists + 2 items, snapshotting the inventory identity.
        $order = ShopOrder::with('items')->where('order_ref', $ref)->firstOrFail();
        $this->assertSame(2, $order->items()->count());
        $this->assertSame('card', $order->payment_method);
        $this->assertNotNull($res->json('expires_at')); // card orders have 30min expiry

        $snapshots = $order->items->pluck('snapshot_name')->all();
        sort($snapshots);
        $this->assertSame(['Package 1', 'Package 2'], $snapshots);
        $this->assertSame(
            [$this->p1->id, $this->p2->id],
            $order->items->pluck('inventory_id')->sort()->values()->all()
        );
    }

    /** @test */
    public function tampered_grand_total_returns_422_total_mismatch()
    {
        $bad = $this->validPayload(['grand_total' => 1.00]); // fake 1.00 vs real ~310
        $res = $this->postJson('/api/public/shop/orders', $bad);
        $res->assertStatus(422)
            ->assertJsonValidationErrors('grand_total');
        $this->assertSame(0, ShopOrder::count());
    }

    /** @test */
    public function tampered_unit_price_returns_422_price_mismatch()
    {
        // R-7: unit_price_ex_vat must match inventories.shop_price for the referenced item.
        $payload = $this->validPayload();
        $payload['items'][0]['unit_price_ex_vat'] = 1.00; // p1 actually costs 100.00

        $res = $this->postJson('/api/public/shop/orders', $payload);
        $res->assertStatus(422)
            ->assertJsonValidationErrors('items.0.unit_price_ex_vat');
        $this->assertSame(0, ShopOrder::count());
    }

    /** @test */
    public function inactive_product_returns_422()
    {
        $this->p1->update(['is_active' => false]);
        $res = $this->postJson('/api/public/shop/orders', $this->validPayload());
        $res->assertStatus(422)
            ->assertJsonValidationErrors('items.0.inventory_id');
        $this->assertSame(0, ShopOrder::count());
    }

    /** @test */
    public function inventory_not_flagged_for_shop_returns_422()
    {
        // R-7: the item must be is_active AND for_shop.
        $this->p1->update(['for_shop' => false]);
        $res = $this->postJson('/api/public/shop/orders', $this->validPayload());
        $res->assertStatus(422)
            ->assertJsonValidationErrors('items.0.inventory_id');
        $this->assertSame(0, ShopOrder::count());
    }

    /** @test */
    public function order_lookup_requires_email_and_returns_404_on_wrong_email()
    {
        // Seed order first (no HTTP request — we want to test show() not throttle).
        ShopOrder::factory()->create([
            'order_ref'      => 'KE-00000000-AAAA',
            'customer_email' => 'toby@example.com',
            'status'         => 'pending',
            'payment_method' => 'card',
        ]);

        // No email → 400 EMAIL_REQUIRED.
        $this->getJson('/api/public/shop/orders/KE-00000000-AAAA')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'EMAIL_REQUIRED');

        // Correct email → 200.
        $this->getJson('/api/public/shop/orders/KE-00000000-AAAA?email=toby@example.com')
            ->assertStatus(200)
            ->assertJsonPath('data.order_ref', 'KE-00000000-AAAA');

        // Wrong email → 404 (no enumeration info leak).
        $this->getJson('/api/public/shop/orders/KE-00000000-AAAA?email=stranger@example.com')
            ->assertStatus(404);

        // Wrong ref altogether → 404.
        $this->getJson('/api/public/shop/orders/KE-99999999-ZZZZ?email=toby@example.com')
            ->assertStatus(404);
    }

    /** @test */
    public function invalid_phone_and_postcode_returns_422_with_project_style_messages()
    {
        $payload = $this->validPayload([
            'customer_phone'   => 'short',
            'billing_postcode' => 'XYZ',
        ]);
        $this->postJson('/api/public/shop/orders', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_phone', 'billing_postcode']);
    }

    /** @test */
    public function empty_items_returns_422_validation_error()
    {
        // U-1: project-style message for a missing item list.
        $payload = $this->validPayload(['items' => []]);
        $this->postJson('/api/public/shop/orders', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    /** @test */
    public function order_post_endpoint_is_throttled_30_per_minute()
    {
        // R-12: 31st request → 429 Too Many Attempts. The first 30 requests are deliberately
        // invalid (grand_total mismatch) so they count towards the limiter without writing rows.
        $bad = $this->validPayload(['grand_total' => 0, 'subtotal_ex_vat' => 0, 'vat_amount' => 0]);

        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/public/shop/orders', $bad);
        }

        $res = $this->postJson('/api/public/shop/orders', $bad);
        $this->assertSame(429, $res->getStatusCode(),
            'Expected 429 on the 31st POST within the same minute, got ' . $res->getStatusCode());
    }
}