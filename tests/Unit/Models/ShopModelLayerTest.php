<?php

namespace Tests\Unit\Models;

// @group shop-new
// T2 R-2: Model wiring, preventLazyLoading guard, relation hydration, and Resource output shape.
//
// The shop is inventory-backed: `Inventory` is the product model (selected by `for_shop`) and
// `InventoryCategory` is the category model. Shop child rows hang off `inventory_id`.

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\Inventory;
use App\Models\ShopProductImage;
use App\Models\ShopProductCustomisationOption;
use App\Models\ShopProductReview;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use App\Models\ShopPaymentTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Http\Request;
use App\Http\Resources\Shop\ShopProductResource;

class ShopModelLayerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable strict mode for every shop entity used in this test file.
        Inventory::preventLazyLoading(true);
        ShopProductImage::preventLazyLoading(true);
        ShopProductReview::preventLazyLoading(true);
        ShopOrder::preventLazyLoading(true);
        ShopOrderItem::preventLazyLoading(true);
        ShopPaymentTransaction::preventLazyLoading(true);
        // Also: raise MassAssignmentException on fillable-discard to make PCI attack-surface testable.
        Model::preventSilentlyDiscardingAttributes(true);
    }

    /** @test */
    public function product_eager_loading_eliminates_n_plus_one_queries_relation_count_matches()
    {
        // Create 3 shop-listed inventory items, each with a category + 1 gallery image.
        $products = Inventory::factory()->count(3)->forShop()->create();
        $products->each(function (Inventory $p) {
            ShopProductImage::create([
                'inventory_id' => $p->id,
                'path'         => "shop/products/{$p->id}/a.jpg",
                'sort_order'   => 1,
                'is_primary'   => true,
            ]);
        });

        // === PART A: WITHOUT eager load — accessing relations should throw LazyLoadingViolationException
        // (because strict guard is enabled in setUp / AppServiceProvider for unit tests).
        $notEager = Inventory::orderBy('id')->get();
        $lazyException = null;
        try {
            foreach ($notEager as $p) {
                $p->category;
                $p->images->count();
            }
        } catch (LazyLoadingViolationException $e) {
            $lazyException = $e;
        }
        $this->assertNotNull($lazyException,
            'Without eager loading, strict guard should throw LazyLoadingViolationException (N-1 guard active).');

        // === PART B: WITH eager load — same iteration should do ZERO extra queries and throw nothing.
        DB::connection()->enableQueryLog();
        DB::flushQueryLog();
        $eager = Inventory::with(['category', 'images'])->orderBy('id')->get();
        $beforeCount = count(DB::getQueryLog());
        foreach ($eager as $p) {
            $this->assertTrue($p->relationLoaded('category'));
            $this->assertTrue($p->relationLoaded('images'));
            $p->category;
            $p->images->count();
        }
        $afterCount = count(DB::getQueryLog());
        $this->assertEquals($beforeCount, $afterCount,
            'Eager loaded relations: iterating should trigger zero additional SQL queries (found ' . ($afterCount - $beforeCount) . ' extra).');
    }

    /** @test */
    public function product_resource_returns_expected_keys_matching_frontend_seed_shape()
    {
        $product = Inventory::factory()->forShop(1000.00)->create([
            'name'    => 'Signature Package',
            'slug'    => 'signature-package',
            'tagline' => 'All the trimmings',
        ]);

        ShopProductImage::create([
            'inventory_id' => $product->id,
            'path'         => 'shop/products/placeholder.jpg',
            'alt_text'     => 'Placeholder',
            'sort_order'   => 1,
            'is_primary'   => true,
        ]);
        ShopProductCustomisationOption::create([
            'inventory_id' => $product->id,
            'option_key'   => 'opt-size',
            'label'        => 'Package Size',
            'option_type'  => 'radio',
            'is_required'  => true,
            'sort_order'   => 1,
            'choices'      => [
                ['value' => 'classic', 'label' => 'Classic', 'price_delta_ex_vat' => 450],
            ],
        ]);
        ShopProductReview::create([
            'inventory_id' => $product->id,
            'author_name'  => 'Alex',
            'rating'       => 5,
            'title'        => 'Stunning!',
            'review_date'  => now()->toDateString(),
            'is_approved'  => true,
        ]);

        $loaded = Inventory::with(['category', 'images', 'customisationOptions', 'reviews'])
                           ->findOrFail($product->id);

        $resource = (new ShopProductResource($loaded))->toArray(Request::create('/'));

        foreach (['id','name','slug','tagline','description','price_ex_vat','price_ex_vat_raw',
                  'price_inc_vat','price_inc_vat_raw','categories','images',
                  'customisation_options','reviews'] as $key) {
            $this->assertArrayHasKey($key, $resource, "Missing resource key $key");
        }

        // The single inventory category is emitted as a 0-or-1 element `categories` list.
        $this->assertCount(1, $resource['categories']);
        $this->assertSame($loaded->category->id, $resource['categories'][0]['id']);
        $this->assertSame($loaded->category->category_name, $resource['categories'][0]['name']);

        // ex-VAT retail price is driven by inventories.shop_price; inc-VAT is x1.2.
        $this->assertSame('1000.00', $resource['price_ex_vat']);
        $this->assertSame('1200.00', $resource['price_inc_vat']);

        // Shape match U-5: confirm customisation_options[].choices carry VAT-inc delta.
        $this->assertEquals(number_format(450 * 1.2, 2, '.', ''),
            $resource['customisation_options'][0]['choices'][0]['price_delta_inc_vat']);

        // Images carry computed URL attribute via getUrlAttribute().
        $this->assertStringContainsString('placeholder.jpg', $resource['images'][0]['url']);

        // Approved review surfaces with the aggregate summary.
        $this->assertCount(1, $resource['reviews']);
        $this->assertSame(5.0, $resource['rating_summary']['average_rating']);
        $this->assertSame(1, $resource['rating_summary']['review_count']);
    }

    /** @test */
    public function order_generate_reference_matches_ke_pattern_and_collision_retries_work()
    {
        $regex = '/^KE-\d{8}-[A-Z0-9]{4}$/';
        $first = ShopOrder::generateReference();
        $this->assertMatchesRegularExpression($regex, $first);

        // Stub existing ref — generator should move on.
        ShopOrder::factory()->create(['order_ref' => $first]);
        $second = ShopOrder::generateReference();
        $this->assertNotEquals($first, $second);
        $this->assertMatchesRegularExpression($regex, $second);
    }

    /** @test */
    public function order_recompute_totals_matches_vat_20_percent_plus_shipping()
    {
        $items = [
            ['unit_price_ex_vat' => '100.00', 'quantity' => 2], // 200 subtotal
            ['unit_price_ex_vat' => '50.50', 'quantity' => 1],  //  50.50
        ];
        $totals = ShopOrder::recomputeTotalsFromItems($items, shipping: 9.99);
        $this->assertEqualsWithDelta(250.50, $totals['subtotal_ex_vat'], 0.001);
        $this->assertEqualsWithDelta(50.10,  $totals['vat_amount'],      0.001);
        $this->assertEqualsWithDelta(310.59, $totals['grand_total'],     0.001); // 250.5 + 50.1 + 9.99
    }

    /** @test */
    public function order_item_stores_inventory_snapshot_and_survives_inventory_deletion()
    {
        $inventory = Inventory::factory()->forShop(100.00)->create(['name' => 'Snapshot Item', 'slug' => 'snapshot-item']);
        $order = ShopOrder::factory()->create(['order_ref' => 'KE-00000000-SNAP']);

        $item = ShopOrderItem::create([
            'shop_order_id'   => $order->id,
            'inventory_id'    => $inventory->id,
            'snapshot_name'   => $inventory->name,
            'snapshot_slug'   => $inventory->slug,
            'unit_price_ex_vat' => 100.00,
            'quantity'        => 2,
            'line_price_ex_vat' => 200.00,
        ]);

        $this->assertSame($inventory->id, $item->inventory()->first()->id);
        $this->assertSame(240.00, $item->line_price_inc_vat);

        // Deleting the inventory item nulls the FK but the snapshot columns remain.
        $inventory->delete();
        $item->refresh();
        $this->assertNull($item->inventory_id);
        $this->assertSame('Snapshot Item', $item->snapshot_name);
        $this->assertSame('snapshot-item', $item->snapshot_slug);
    }

    /** @test */
    public function payment_transaction_fillable_whitelist_has_no_card_fields()
    {
        $tx = new ShopPaymentTransaction();
        $fillable = $tx->getFillable();

        foreach (['card_number','cvc','cvv','expiry_month','pan','track_data','card_holder'] as $forbidden) {
            $this->assertNotContains($forbidden, $fillable,
                "PCI: $forbidden must not be in ShopPaymentTransaction fillable");
        }

        // Try to fill with a card_number — `shouldBeStrict` should throw MassAssignmentException
        // (strict-mode discards → raises instead of silently dropping non-fillable).
        $this->expectException(\Illuminate\Database\Eloquent\MassAssignmentException::class);
        $tx->fill([
            'transaction_ref' => 'TX-1',
            'provider'        => 'simulated',
            'amount'          => 1,
            'card_number'     => '4242424242424242', // attacker-supplied, not fillable
        ]);
    }
}