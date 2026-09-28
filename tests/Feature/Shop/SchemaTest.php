<?php

namespace Tests\Feature\Shop;

// @group shop-new
// R-2: Verifies the inventory-backed shop schema — the columns, unique indexes and FKs behind
// each shop surface. The shop no longer owns a catalogue: shop products ARE `inventories` rows
// (`for_shop` + `shop_price`) and shop categories ARE `inventory_categories` rows (`for_shop`).
// Every shop_* child table now references `inventories.inventory_id` (not shop_product_id).

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Concatenated SQLite index names for a table — used to assert unique indexes exist.
     */
    private function indexNames(string $table): string
    {
        return collect(DB::select("PRAGMA index_list({$table})"))->pluck('name')->join(' ');
    }

    /** @test */
    public function inventories_has_shop_columns_and_unique_slug_index()
    {
        $schema = Schema::getColumnListing('inventories');

        foreach ([
            'id', 'name', 'slug', 'description', 'tagline',
            'price', 'cost_price', 'shop_price', 'for_shop',
            'inventory_category_id',
            'suitable_for_guests_min', 'suitable_for_guests_max', 'suitable_for_guests_label',
            'is_active', 'created_at', 'updated_at',
        ] as $col) {
            $this->assertContains($col, $schema, "Missing inventories column $col");
        }

        $this->assertStringContainsString('inventories_slug_unique', $this->indexNames('inventories'));
    }

    /** @test */
    public function inventory_categories_has_shop_columns_and_unique_slug_index()
    {
        $schema = Schema::getColumnListing('inventory_categories');

        foreach (['id', 'category_name', 'category_description', 'category_image',
                  'slug', 'for_shop', 'created_at', 'updated_at'] as $col) {
            $this->assertContains($col, $schema, "Missing inventory_categories column $col");
        }

        $this->assertStringContainsString('inventory_categories_slug_unique', $this->indexNames('inventory_categories'));
    }

    /** @test */
    public function legacy_shop_catalogue_tables_and_pivot_are_gone()
    {
        $this->assertFalse(Schema::hasTable('shop_products'), 'shop_products should be deleted in favour of inventories.');
        $this->assertFalse(Schema::hasTable('shop_categories'), 'shop_categories should be deleted in favour of inventory_categories.');
        $this->assertFalse(Schema::hasTable('shop_category_product'), 'The many-to-many pivot is gone — Inventory has one category.');
    }

    /** @test */
    public function shop_child_tables_fk_to_inventory_id_and_not_shop_product_id()
    {
        foreach ([
            'shop_product_images',
            'shop_product_customisation_options',
            'shop_product_reviews',
            'shop_client_basket_items',
            'shop_order_items',
        ] as $table) {
            $schema = Schema::getColumnListing($table);
            $this->assertContains('inventory_id', $schema, "Missing inventory_id on $table");
            $this->assertNotContains('shop_product_id', $schema, "Legacy shop_product_id still present on $table");
        }
    }

    /** @test */
    public function shop_orders_has_unique_order_ref_and_indexes()
    {
        $schema = Schema::getColumnListing('shop_orders');
        foreach (['order_ref','status','customer_full_name','customer_email','customer_phone',
                  'billing_address_line_1','billing_city','billing_postcode',
                  'subtotal_ex_vat','vat_amount','shipping_amount','grand_total',
                  'payment_method','event_date','event_venue','source_ref','notes'] as $col) {
            $this->assertContains($col, $schema, "Missing column $col");
        }

        $this->assertStringContainsString('shop_orders_order_ref_unique', $this->indexNames('shop_orders'));
    }

    /** @test */
    public function shop_payment_transactions_no_card_data_and_unique_transaction_ref()
    {
        $schema = Schema::getColumnListing('shop_payment_transactions');
        foreach (['transaction_ref','provider','provider_payment_id','status','amount',
                  'currency','raw_metadata','failure_reason','processed_at'] as $col) {
            $this->assertContains($col, $schema, "Missing column $col");
        }

        // PCI: No raw card data fields allowed in schema.
        $this->assertNotContains('card_number', $schema);
        $this->assertNotContains('cvc', $schema);
        $this->assertNotContains('pan', $schema);
        $this->assertNotContains('cvv', $schema);
        $this->assertNotContains('expiry_month', $schema);

        $this->assertStringContainsString('shop_payment_transactions_transaction_ref_unique', $this->indexNames('shop_payment_transactions'));
    }

    /** @test */
    public function shop_order_items_has_inventory_fk_and_snapshot_columns()
    {
        $schema = Schema::getColumnListing('shop_order_items');
        foreach (['inventory_id','snapshot_name','snapshot_slug','snapshot_variant_label',
                  'unit_price_ex_vat','quantity','line_price_ex_vat','customisations'] as $col) {
            $this->assertContains($col, $schema, "Missing column $col");
        }
    }

    /** @test */
    public function secondary_entity_tables_exist_with_required_fields()
    {
        $this->assertContains('inventory_id', Schema::getColumnListing('shop_product_images'));
        $this->assertContains('path', Schema::getColumnListing('shop_product_images'));
        $this->assertContains('alt_text', Schema::getColumnListing('shop_product_images'));
        $this->assertContains('sort_order', Schema::getColumnListing('shop_product_images'));
        $this->assertContains('is_primary', Schema::getColumnListing('shop_product_images'));

        $cust = Schema::getColumnListing('shop_product_customisation_options');
        $this->assertContains('inventory_id', $cust);
        $this->assertContains('option_key', $cust);
        $this->assertContains('label', $cust);
        $this->assertContains('option_type', $cust);
        $this->assertContains('choices', $cust);

        $rev = Schema::getColumnListing('shop_product_reviews');
        $this->assertContains('inventory_id', $rev);
        $this->assertContains('author_name', $rev);
        $this->assertContains('rating', $rev);
        $this->assertContains('review_date', $rev);
        $this->assertContains('is_approved', $rev);
    }
}