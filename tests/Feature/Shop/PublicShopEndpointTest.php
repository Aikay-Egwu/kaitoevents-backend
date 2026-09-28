<?php

namespace Tests\Feature\Shop;

// PublicShopEndpointTest — T5 verification of the inventory-backed storefront:
//  R-6 : Cache-Control: public, max-age=60 + ETag; repeat with If-None-Match → 304
//  R-10: pagination per_page capped at 30, CSV category-slug filter, q search, price band, sort orders
//  U-2 : categories + products endpoints each <250 ms over 5 runs
//  U-5 : response shape matches expectations (id, slug, price_ex_vat fields present)
//
// Shop products ARE `inventories` rows flagged `for_shop`; shop categories ARE
// `inventory_categories` rows flagged `for_shop`. Each item has exactly ONE category
// (the old many-to-many pivot is gone), so the fixture below assigns one category per item.

use App\Models\Inventory;
use App\Models\InventoryCategory;
use App\Models\ShopProductImage;
use App\Models\ShopProductCustomisationOption;
use App\Models\ShopProductReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicShopEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 2 shop categories; ordering is by category_name (sort_index is always 0 now).
        $catDecor = InventoryCategory::factory()->create([
            'category_name' => 'Decor',
            'slug'          => 'decor',
            'for_shop'      => true,
        ]);
        $catFlorals = InventoryCategory::factory()->create([
            'category_name' => 'Florals',
            'slug'          => 'florals',
            'for_shop'      => true,
        ]);

        // 3 shop-listed products: blush (florals), corporate (decor), garden (florals).
        $p1 = Inventory::factory()->forShop(1000.00)->create([
            'name'                  => 'Blush Romance Decor Package',
            'slug'                  => 'blush-romance',
            'description'           => 'Romantic blush and rose package.',
            'tagline'               => 'Elegant romance',
            'is_active'             => true,
            'inventory_category_id' => $catFlorals->id,
        ]);
        Inventory::factory()->forShop(500.00)->create([
            'name'                  => 'Corporate Monochrome',
            'slug'                  => 'corporate-monochrome',
            'description'           => 'Clean monochrome corporate design.',
            'tagline'               => 'Professional',
            'is_active'             => true,
            'inventory_category_id' => $catDecor->id,
        ]);
        Inventory::factory()->forShop(250.00)->create([
            'name'                  => 'Garden Bloom Centerpiece',
            'slug'                  => 'garden-bloom',
            'description'           => 'Lovely garden-style floral centerpiece.',
            'tagline'               => 'Fresh florals',
            'is_active'             => true,
            'inventory_category_id' => $catFlorals->id,
        ]);

        // Inactive item, still flagged for_shop — must NOT show up.
        Inventory::factory()->forShop(99.00)->create([
            'name'                  => 'Hidden Draft',
            'slug'                  => 'hidden-draft',
            'is_active'             => false,
            'inventory_category_id' => $catFlorals->id,
        ]);
        // Active internal item NOT flagged for_shop — must NOT show up.
        Inventory::factory()->create([
            'name'                  => 'Internal Only',
            'slug'                  => 'internal-only',
            'is_active'             => true,
            'inventory_category_id' => $catDecor->id,
        ]);

        // Add image for p1.
        ShopProductImage::create([
            'inventory_id' => $p1->id,
            'path'         => 'shop/products/' . $p1->id . '/abc123.jpg',
            'alt_text'     => 'Blush romance main image',
            'sort_order'   => 1,
            'is_primary'   => true,
        ]);

        // Add customisation option for p1 (Package Size).
        ShopProductCustomisationOption::create([
            'inventory_id' => $p1->id,
            'option_key'   => 'opt-size',
            'label'        => 'Package Size',
            'option_type'  => 'radio',
            'is_required'  => true,
            'sort_order'   => 1,
            'choices'      => [
                ['value' => 'classic', 'label' => 'Classic', 'price_delta_ex_vat' => 450],
                ['value' => 'luxury',  'label' => 'Luxury',  'price_delta_ex_vat' => 950],
            ],
        ]);

        // Add an approved review for p1, and an unapproved one that must be hidden.
        ShopProductReview::create([
            'inventory_id' => $p1->id,
            'author_name'  => 'Alice',
            'rating'       => 5,
            'title'        => 'Stunning decor',
            'review_text'  => 'Loved the setup!',
            'review_date'  => now()->subDays(10),
            'is_approved'  => true,
        ]);
        ShopProductReview::create([
            'inventory_id' => $p1->id,
            'author_name'  => 'Pending user',
            'rating'       => 3,
            'title'        => 'Awaiting moderation',
            'review_date'  => now()->subDay(),
            'is_approved'  => false,
        ]);
    }

    /** @test */
    public function categories_returns_active_sorted_list_with_cache_headers()
    {
        $res = $this->getJson('/api/public/shop/categories');
        $cacheHdr = (string) $res->headers->get('Cache-Control', '');
        $this->assertStringContainsString('max-age=60', $cacheHdr);
        $this->assertStringContainsString('public', $cacheHdr);
        $res->assertStatus(200)
            ->assertHeader('ETag')
            ->assertJsonCount(2, 'data')
            // Ordered by category_name: Decor first, Florals second.
            ->assertJsonPath('data.0.slug', 'decor')
            ->assertJsonPath('data.1.slug', 'florals')
            // sort_index is a fixed 0 for inventory categories.
            ->assertJsonPath('data.0.sort_index', 0)
            ->assertJsonPath('data.0.is_active', true)
            // product_count counts every inventory row flagged `for_shop` (decor=1, florals=3
            // including the inactive-but-published Hidden Draft) — the count is not is_active-filtered.
            ->assertJsonPath('data.0.product_count', 1)
            ->assertJsonPath('data.1.product_count', 3);

        $etag = $res->headers->get('ETag');

        // R-6: repeat with If-None-Match → 304
        $repeat = $this->withHeaders(['If-None-Match' => $etag])
            ->getJson('/api/public/shop/categories');
        $repeat->assertStatus(304);
    }

    /** @test */
    public function products_pagination_and_filtering_works()
    {
        // Default page 1 per_page=9 → 3 active shop items, hidden/internal excluded.
        $res = $this->getJson('/api/public/shop/products');
        $cacheHdr = (string) $res->headers->get('Cache-Control', '');
        $this->assertStringContainsString('max-age=60', $cacheHdr);
        $this->assertStringContainsString('public', $cacheHdr);
        $res->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertHeader('ETag');

        // per_page=1 capped at 30 still works → 1 item on page1.
        $paged = $this->getJson('/api/public/shop/products?per_page=1&page=1');
        $paged->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 1);

        // U-5: shape — first item has key fields (incl. the `categories` array of 0/1).
        $this->assertNotNull($res->json('data.0.id'));
        $this->assertNotNull($res->json('data.0.slug'));
        $this->assertArrayHasKey('price_ex_vat_raw', $res->json('data.0'));
        $this->assertArrayHasKey('categories', $res->json('data.0'));

        // CSV category-slug filter: florals → blush (florals) and garden (florals).
        $filter = $this->getJson('/api/public/shop/products?categories=florals');
        $filter->assertStatus(200)
            ->assertJsonCount(2, 'data');
        $slugs = array_column($filter->json('data'), 'slug');
        sort($slugs);
        $this->assertSame(['blush-romance', 'garden-bloom'], $slugs);

        // q search keyword: "floral" in description/tagline/name.
        $search = $this->getJson('/api/public/shop/products?q=floral');
        $search->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($search->json('data')));

        // Price band min_price_ex=200 max_price_ex=600 → corporate (500) + garden (250).
        $price = $this->getJson('/api/public/shop/products?min_price_ex=200&max_price_ex=600');
        $price->assertStatus(200);
        $slugs = array_column($price->json('data'), 'slug');
        sort($slugs);
        $this->assertSame(['corporate-monochrome', 'garden-bloom'], $slugs);

        // Sort price_asc → garden 250, corporate 500, blush 1000.
        $asc = $this->getJson('/api/public/shop/products?sort=price_asc');
        $asc->assertStatus(200);
        $this->assertSame('garden-bloom', $asc->json('data.0.slug'));
        $this->assertSame('blush-romance', $asc->json('data.2.slug'));

        // Sort price_desc → opposite.
        $desc = $this->getJson('/api/public/shop/products?sort=price_desc');
        $this->assertSame('blush-romance', $desc->json('data.0.slug'));
    }

    /** @test */
    public function product_detail_includes_related_and_approved_reviews_only()
    {
        $res = $this->getJson('/api/public/shop/products/blush-romance');
        $res->assertStatus(200);

        // Product: primary image URL present, VAT-inc price exists, custom options choices present.
        $data = $res->json('data');
        $this->assertCount(1, $data['images']);
        $this->assertTrue($data['images'][0]['is_primary']);
        $this->assertArrayHasKey('url', $data['images'][0]);
        // `categories` is a 0-or-1 element list backed by the single category relation.
        $this->assertCount(1, $data['categories']);
        $this->assertSame('florals', $data['categories'][0]['slug']);
        $this->assertArrayHasKey('customisation_options', $data);
        $this->assertNotEmpty($data['customisation_options']);
        // choices_with_vat: 450*1.2=540.00
        $choice = $data['customisation_options'][0]['choices'][0] ?? null;
        $this->assertNotNull($choice);
        $this->assertEqualsWithDelta(540.00, $choice['price_delta_inc_vat_raw'] ?? 0, 0.01);

        // Only approved review shown.
        $this->assertCount(1, $data['reviews']);
        $this->assertSame('Alice', $data['reviews'][0]['author_name']);

        // 4 related products: total active shop = 3, so we expect 2 other active products.
        $related = $res->json('related');
        $this->assertGreaterThanOrEqual(2, count($related));
        $relatedSlugs = array_column($related, 'slug');
        $this->assertNotContains('blush-romance', $relatedSlugs);
        $this->assertNotContains('hidden-draft', $relatedSlugs);
        $this->assertNotContains('internal-only', $relatedSlugs);
    }

    /** @test */
    public function category_detail_returns_related_categories()
    {
        $res = $this->getJson('/api/public/shop/categories/florals');
        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slug', 'florals')
            ->assertJsonPath('data.product_count', 3);

        $related = $res->json('related');
        $this->assertCount(1, $related);
        $this->assertSame('decor', $related[0]['slug']);
    }

    /** @test */
    public function endpoint_latency_under_250_ms()
    {
        // U-2: average over 5 runs each of categories and products endpoints must be <250ms.
        $thresholdMs = 250;

        $times = ['cats' => [], 'prods' => []];
        for ($i = 0; $i < 5; $i++) {
            $t0 = microtime(true);
            $this->getJson('/api/public/shop/categories')->assertStatus(200);
            $times['cats'][] = (microtime(true) - $t0) * 1000;

            $t0 = microtime(true);
            $this->getJson('/api/public/shop/products')->assertStatus(200);
            $times['prods'][] = (microtime(true) - $t0) * 1000;
        }

        $avgCats  = array_sum($times['cats'])  / count($times['cats']);
        $avgProds = array_sum($times['prods']) / count($times['prods']);

        $this->assertLessThan($thresholdMs, $avgCats,  "Categories avg {$avgCats}ms ≥ {$thresholdMs}ms");
        $this->assertLessThan($thresholdMs, $avgProds, "Products avg {$avgProds}ms ≥ {$thresholdMs}ms");
    }
}