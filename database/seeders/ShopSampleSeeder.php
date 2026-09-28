<?php

namespace Database\Seeders;

// ShopSampleSeeder — publishes existing inventory records to the shop.
// The shop no longer owns a separate catalogue: products ARE inventory items flagged
// `for_shop`, and shop categories are inventory categories flagged `for_shop`.
//
// inventory_categories is a 2-level hierarchy and inventory rows hang off the CHILD
// categories, so publishing a root category must include its children.
// Safe to run repeatedly — it only flips flags and fills a missing shop price.

use App\Models\Inventory;
use App\Models\InventoryCategory;
use Illuminate\Database\Seeder;

class ShopSampleSeeder extends Seeder
{
    private const CATEGORY_LIMIT = 6;
    private const CHILD_LIMIT = 12;
    private const ITEM_LIMIT = 14;

    /** Placeholder retail price used when the inventory record carries no price of its own. */
    private const PLACEHOLDER_SHOP_PRICE = 25.00;

    public function run(): void
    {
        $rootIds = InventoryCategory::query()
            ->whereNull('parent_id')
            ->orderBy('id')
            ->limit(self::CATEGORY_LIMIT)
            ->pluck('id')
            ->all();

        // Inventory rows live on the child categories, so include them too.
        $childIds = InventoryCategory::query()
            ->whereIn('parent_id', $rootIds)
            ->orderBy('id')
            ->limit(self::CHILD_LIMIT)
            ->pluck('id')
            ->all();

        $publishIds = array_values(array_unique(array_merge($rootIds, $childIds)));

        if (count($publishIds) === 0) {
            $this->command?->warn('ShopSampleSeeder: no inventory categories found — nothing published.');
            return;
        }

        InventoryCategory::query()->whereIn('id', $publishIds)->update(['for_shop' => true]);

        $items = Inventory::query()
            ->whereIn('inventory_category_id', $publishIds)
            ->orderBy('id')
            ->limit(self::ITEM_LIMIT)
            ->get();

        $placeholders = 0;

        foreach ($items as $item) {
            $shopPrice = $item->shop_price;

            if ($shopPrice === null || (float) $shopPrice <= 0) {
                if ((float) $item->price > 0) {
                    // Derive from the internal hire price plus a small margin.
                    $shopPrice = round((float) $item->price * 1.15, 2);
                } else {
                    // No inventory price to derive from — use a clearly-labelled placeholder
                    // so the dev storefront still renders usable prices.
                    $shopPrice = self::PLACEHOLDER_SHOP_PRICE;
                    $placeholders++;
                }
            }

            $item->update([
                'for_shop'   => true,
                'shop_price' => $shopPrice,
            ]);
        }

        $this->command?->info(sprintf(
            'ShopSampleSeeder: published %d categories and %d inventory items to the shop.',
            count($publishIds),
            $items->count(),
        ));

        if ($placeholders > 0) {
            $this->command?->warn(sprintf(
                '%d published items had no inventory price and were given a £%.2f placeholder. '
                . 'Set real shop prices in the shop admin before going live.',
                $placeholders,
                self::PLACEHOLDER_SHOP_PRICE,
            ));
        }
    }
}