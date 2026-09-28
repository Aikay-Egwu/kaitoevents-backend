<?php

namespace App\Http\Resources\Shop;

// ShopProductResource — shop listing/detail payload. Backed by Inventory, which is the
// single source of shop products (selected via the `for_shop` flag). The emitted keys are
// unchanged so existing API consumers and the storefront keep working.

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShopProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Identity
            'id'          => (int) $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'tagline'     => $this->tagline,
            'description' => $this->description,

            // Pricing (formatted + raw) — shop_price is the ex-VAT retail price
            'price_ex_vat'         => number_format((float) $this->shop_price, 2, '.', ''),
            'price_ex_vat_raw'     => (float) $this->shop_price,
            'price_inc_vat'        => $this->price_inc_vat,
            'price_inc_vat_raw'    => $this->price_inc_vat_raw,

            // Suitability label (matches frontend suitableForGuests label)
            'suitable_for_guests_min'   => $this->suitable_for_guests_min,
            'suitable_for_guests_max'   => $this->suitable_for_guests_max,
            'suitable_for_guests_label' => $this->suitable_for_guests_label,

            'is_active' => (bool) $this->is_active,

            // Inventory has one category per item, but the storefront contract expects a list.
            'categories' => $this->whenLoaded('category', function () {
                if ($this->category === null) {
                    return [];
                }

                return [[
                    'id'   => (int) $this->category->id,
                    'name' => $this->category->category_name,
                    'slug' => $this->category->slug,
                ]];
            }),

            'images' => $this->whenLoaded('images', function () {
                return $this->images->map(function ($img) {
                    return [
                        'id'         => (int)$img->id,
                        'url'        => $img->url,
                        'alt'        => $img->alt_text,
                        'is_primary' => (bool)$img->is_primary,
                        'sort_order' => (int)$img->sort_order,
                    ];
                })->values();
            }),

            'customisation_options' => $this->whenLoaded('customisationOptions', function () {
                return $this->customisationOptions->map(function ($opt) {
                    return [
                        'id'          => (int)$opt->id,
                        'option_key'  => $opt->option_key,
                        'label'       => $opt->label,
                        'type'        => $opt->option_type, // radio/select — matches frontend type
                        'is_required' => (bool)$opt->is_required,
                        'sort_order'  => (int)$opt->sort_order,
                        'choices'     => $opt->choices_with_vat, // computed with inc VAT deltas
                    ];
                })->values();
            }),

            'reviews' => $this->whenLoaded('reviews', function () {
                return $this->reviews->map(function ($rev) {
                    return [
                        'id'          => (int)$rev->id,
                        'author_name' => $rev->author_name,
                        'rating'      => (int)$rev->rating,
                        'title'       => $rev->title,
                        'review_text' => $rev->review_text,
                        'review_date' => $rev->review_date?->toDateString(),
                    ];
                })->values();
            }),

            // Aggregate rating (if reviews loaded)
            'rating_summary' => $this->when($this->relationLoaded('reviews') && $this->reviews->count() > 0, function () {
                $total = $this->reviews->sum('rating');
                $count = $this->reviews->count();

                return [
                    'average_rating' => round($total / max(1, $count), 1),
                    'review_count'   => $count,
                ];
            }),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}