<?php

namespace Tests\Feature\Shop;

// T9 E2E 3 HTTP smoke calls against the inventory-backed shop:
//   (i)  GET  /api/public/shop/products?per_page=14 → meta.total=14
//   (ii) POST /api/public/shop/orders → 201 + KE-YYYYMMDD-XXXX ref
//   (iii)GET  /api/public/shop/orders/{ref}?email={email} → 200
// Uses HTTP request builders in-process (no artisan serve needed for sandbox).
//
// Shop products ARE inventory rows flagged `for_shop`, so this test boots the real inventory
// catalogue (DatabaseSeeder) and publishes a 14-item slice to the shop — which is precisely
// what ShopSampleSeeder is documented to do. See the note in publishInventoryToShop().

use App\Models\Inventory;
use App\Models\InventoryCategory;
use App\Models\ShopOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopEndToEndSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Real catalogue: 14 root + 148 child inventory categories and 440 inventory items.
        $this->artisan('db:seed');

        $this->publishInventoryToShop(14);
    }

    /**
     * Publish the first N inventory items to the shop, matching ShopSampleSeeder's intent.
     *
     * NOTE: ShopSampleSeeder currently publishes ZERO items — it selects inventory rows whose
     * `inventory_category_id` is one of the ROOT category ids, but every seeded inventory row
     * points at a CHILD category. It is left untouched (database/ is out of scope for this
     * refactor); this helper reproduces the documented behaviour so the smoke test still
     * exercises 14 real, API-visible products. Reported as a genuine application bug.
     */
    private function publishInventoryToShop(int $limit): void
    {
        $items = Inventory::query()->orderBy('id')->limit($limit)->get();

        InventoryCategory::whereIn('id', $items->pluck('inventory_category_id')->unique()->all())
            ->update(['for_shop' => true]);

        foreach ($items as $item) {
            $item->update([
                'for_shop'   => true,
                'shop_price' => round((float) $item->price * 1.15, 2),
            ]);
        }
    }

    /** @test */
    public function smoke_get_products_returns_14_total()
    {
        $response = $this->getJson('/api/public/shop/products?per_page=14');
        $response->assertStatus(200);
        $response->assertJsonPath('meta.total', 14);
        $response->assertJsonCount(14, 'data');

        // Every listed row is a published inventory item and exposes the shop contract keys.
        $first = $response->json('data.0');
        $this->assertArrayHasKey('slug', $first);
        $this->assertArrayHasKey('price_ex_vat_raw', $first);
        $this->assertArrayHasKey('categories', $first);
    }

    /** @test */
    public function smoke_post_order_returns_ke_reference_and_then_lookup_200()
    {
        // Load the first published inventory item (shop product) from the seeded catalogue.
        $product = Inventory::forShop()->active()->orderBy('id')->firstOrFail();
        $qty = 3;
        $unitPriceExVat = (float) $product->shop_price;
        $subTotal = round($unitPriceExVat * $qty, 2);
        $vat = round($subTotal * 0.20, 2);
        $shipping = 45;
        $grandTotal = round($subTotal + $vat + $shipping, 2);

        $email = 'e2e-smoke@kaitoevents.test';
        $postcode = 'SW1A 1AA';
        $phone = '+44 7700 900123';

        $payload = [
            'customer_full_name'      => 'E2e Smoke',
            'customer_email'          => $email,
            'customer_phone'          => $phone,
            'billing_address_line_1'  => '10 Downing Street',
            'billing_city'            => 'London',
            'billing_postcode'        => $postcode,
            'billing_country'         => 'GB',
            'payment_method'          => 'card',
            'items' => [
                [
                    'inventory_id'      => $product->id,
                    'quantity'          => $qty,
                    'unit_price_ex_vat' => $unitPriceExVat,
                    'customisations'    => [],
                ],
            ],
            'shipping_amount'         => $shipping,
            'subtotal_ex_vat'         => $subTotal,
            'vat_amount'              => $vat,
            'grand_total'             => $grandTotal,
            'notes'                   => 'T9 E2E smoke payload',
            'source_ref'              => 'shop-e2e-smoke',
        ];

        $postResp = $this->postJson('/api/public/shop/orders', $payload);
        $status = $postResp->getStatusCode();
        $body = $postResp->getContent();
        $this->assertSame(201, $status, "POST /orders failed status={$status} body={$body}");
        $json = $postResp->json();
        $ref = $json['data']['order_ref'] ?? null;
        $this->assertNotEmpty($ref, 'POST order returned no reference. JSON=' . json_encode($json));
        $this->assertMatchesRegularExpression('/^KE-\d{8}-[A-Z0-9]{4}$/', $ref, 'Reference should match KE-YYYYMMDD-XXXX pattern');

        // Ensure persisted in DB
        $row = ShopOrder::where('order_ref', $ref)->first();
        $this->assertNotNull($row, "Order not in DB after POST. Ref={$ref}");
        $this->assertSame($email, $row->customer_email, 'Customer email mismatch in DB');
        $this->assertSame('awaiting_payment', $row->status, "Order status is {$row->status}, expected awaiting_payment");

        // Line item snapshots the inventory identity.
        $this->assertSame($product->id, $row->items()->first()->inventory_id);

        // (iii) GET lookup → 200 (email match required)
        $getResp = $this->getJson("/api/public/shop/orders/{$ref}?email=" . urlencode($email));
        $getResp->assertStatus(200);
        $this->assertSame($ref, $getResp->json('data.order_ref'));

        // wrong email → 404 (no enumeration)
        $this->getJson("/api/public/shop/orders/{$ref}?email=wrong@example.test")
            ->assertStatus(404);
    }
}