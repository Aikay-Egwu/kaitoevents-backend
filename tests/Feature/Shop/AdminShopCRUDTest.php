<?php

namespace Tests\Feature\Shop;

// AdminShopCRUDTest — verifies T4 admin CRUD behavior over the inventory-backed shop:
//   R-4  : duplicate-name category auto-uniquifies slug (decor → decor-2)
//   R-4  : delete non-empty category without force → 400 + code CATEGORY_HAS_PRODUCTS
//   R-4  : delete non-empty category with ?force=1 → 200 + unpublished (inventory kept, category unpublished)
//   R-4  : delete empty category → 204 hard delete
//   R-5  : 14 products, page=2 per_page=9 → 5 items, meta.total=14
//   R-11 : unauthenticated GET /api/admin/shop/categories → 401
//   U-1  : validation messages match project style (tested via 422 + JSON errors key)
//
// Shop products ARE inventory rows (`for_shop`), shop categories ARE inventory_categories rows
// (`for_shop`); there is no pivot any more — each inventory row holds a single category.

use App\Models\User;
use App\Models\Inventory;
use App\Models\InventoryCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminShopCRUDTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    /** @test */
    public function unauthenticated_admin_request_returns_401()
    {
        // R-11: no Bearer token → 401 on protected shop admin endpoint.
        $this->getJson('/api/admin/shop/categories')
            ->assertStatus(401);
    }

    /** @test */
    public function creating_duplicate_name_category_generates_unique_slug()
    {
        // R-4: create first "Decor" → slug "decor"; create another "Decor" → slug "decor-2".
        Sanctum::actingAs($this->admin);

        $first = $this->postJson('/api/admin/shop/categories', [
            'name'        => 'Decor',
            'description' => 'First decor category.',
        ]);
        $first->assertStatus(201);
        $this->assertSame('decor', $first->json('data.slug'));

        $second = $this->postJson('/api/admin/shop/categories', [
            'name'        => 'Decor',
            'description' => 'Duplicate decor name — slug must be uniquified.',
        ]);
        $second->assertStatus(201);
        $this->assertSame('decor-2', $second->json('data.slug'));
    }

    /** @test */
    public function deleting_category_with_inventory_requires_force_flag()
    {
        // R-4: (a) delete without force → 400 + code CATEGORY_HAS_PRODUCTS.
        //      (b) delete with ?force=1 → 200 unpublished (inventory is NOT deleted).
        //      (c) delete an empty category → 204 hard delete.
        Sanctum::actingAs($this->admin);

        $cat = InventoryCategory::factory()->create([
            'category_name' => 'Florals',
            'for_shop'      => true,
        ]);
        $prod = Inventory::factory()->forShop(120.00)->create([
            'name'                  => 'Test Package',
            'inventory_category_id' => $cat->id,
        ]);

        $this->assertSame(1, $cat->fresh()->inventoryItems()->count());
        $this->assertSame(1, $cat->fresh()->shopItems()->count());

        // (a) no force → guard fires.
        $noForce = $this->deleteJson('/api/admin/shop/categories/' . $cat->id);
        $noForce->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'CATEGORY_HAS_PRODUCTS');

        // (b) with force → unpublishes the category; inventory rows are preserved.
        $withForce = $this->deleteJson('/api/admin/shop/categories/' . $cat->id . '?force=1');
        $withForce->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('unpublished', true);

        $this->assertNotNull(InventoryCategory::find($cat->id), 'Forced delete must unpublish, not delete.');
        $this->assertFalse((bool) InventoryCategory::find($cat->id)->for_shop);
        // Inventory still exists (belongs to the inventory module, not the shop).
        $this->assertNotNull(Inventory::find($prod->id));

        // (c) empty category → hard delete.
        $empty = InventoryCategory::factory()->create(['for_shop' => true]);
        $this->deleteJson('/api/admin/shop/categories/' . $empty->id)->assertStatus(204);
        $this->assertNull(InventoryCategory::find($empty->id));
    }

    /** @test */
    public function product_index_pagination_respects_page_and_per_page()
    {
        // R-5: seed 14 shop-listed inventory items → page 2 with per_page=9 returns 5, meta.total=14.
        Sanctum::actingAs($this->admin);

        Inventory::factory()->count(14)->forShop()->create();

        $page2 = $this->getJson('/api/admin/shop/products?page=2&per_page=9');
        $page2->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 14)
            ->assertJsonPath('meta.per_page', 9)
            ->assertJsonPath('meta.current_page', 2);
    }

    /** @test */
    public function product_index_only_lists_inventory_flagged_for_shop()
    {
        // The `for_shop` flag is the single gate for the shop catalogue.
        Sanctum::actingAs($this->admin);

        $listed = Inventory::factory()->forShop(80.00)->create(['name' => 'Listed Item']);
        Inventory::factory()->create(['name' => 'Internal Item']); // for_shop defaults to 0

        $res = $this->getJson('/api/admin/shop/products');
        $res->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $listed->id);
    }

    /** @test */
    public function invalid_category_payload_returns_422_with_validation_errors()
    {
        // U-1: validation messages follow project style (JSON errors key present).
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/admin/shop/categories', [
            // Missing required 'name'.
            'description' => 'No name supplied.',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors'])
            ->assertJsonValidationErrors('name');
    }

    /** @test */
    public function store_product_rejects_duplicate_slug_against_inventories()
    {
        // U-1 / R-4: slug uniqueness now lives on `inventories`, not `shop_products`.
        Sanctum::actingAs($this->admin);

        $cat = InventoryCategory::factory()->create(['for_shop' => true]);
        Inventory::factory()->forShop(50.00)->create(['slug' => 'taken-slug']);

        $res = $this->postJson('/api/admin/shop/products', [
            'name'                  => 'Another Product',
            'slug'                  => 'taken-slug',
            'price_ex_vat'          => 50.00,
            'inventory_category_id' => $cat->id,
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors('slug');
    }
}