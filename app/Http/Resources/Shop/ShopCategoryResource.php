<?php

namespace App\Http\Resources\Shop;

// ShopCategoryResource — lightweight category list/detail payload. Backed by InventoryCategory;
// categories are surfaced in the shop via the `for_shop` flag. Emitted keys are unchanged.

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShopCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => (int) $this->id,
            'name'          => $this->category_name,
            'slug'          => $this->slug,
            'description'   => $this->category_description,
            'thumbnail_url' => $this->thumbnail_url,
            'product_count' => $this->whenCounted('shopItems'),
            // Inventory categories carry no sort column — ordering comes from the query.
            'sort_index'    => 0,
            'is_active'     => (bool) $this->for_shop,
            'products'      => $this->whenLoaded('shopItems', function () {
                return ShopProductResource::collection($this->shopItems);
            }),
        ];
    }
}