<?php

namespace App\Http\Resources\Client;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientBasketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $totals = $this->computeTotals();

        return [
            'id' => $this->id,
            'status' => $this->status,
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'line_key' => $item->line_key,
                'inventory_id' => $item->inventory_id,
                'product' => [
                    'id' => $item->inventory->id,
                    'name' => $item->inventory->name,
                    'slug' => $item->inventory->slug,
                    'thumbnail_url' => $item->inventory->images->firstWhere('is_primary', true)?->url
                        ?? $item->inventory->images->first()?->url
                        ?? null,
                ],
                'quantity' => $item->quantity,
                'unit_price_ex_vat' => (float) $item->unit_price_ex_vat,
                'variant_label' => $item->variant_label,
                'selected_options' => $item->selected_options,
            ]),
            'totals' => $totals,
        ];
    }
}
