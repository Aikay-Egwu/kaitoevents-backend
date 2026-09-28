<?php

namespace App\Http\Resources\Shop;

// ShopOrderItemResource — single immutable line-item row (snapshot data preserved).

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShopOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => (int) $this->id,
            'inventory_id'           => $this->inventory_id, // may be null if the inventory item was deleted
            'snapshot_name'          => $this->snapshot_name,
            'snapshot_slug'          => $this->snapshot_slug,
            'snapshot_variant_label' => $this->snapshot_variant_label,
            'quantity'               => (int) $this->quantity,
            'unit_price_ex_vat'      => number_format((float)$this->unit_price_ex_vat, 2, '.', ''),
            'unit_price_inc_vat'     => number_format($this->unit_price_inc_vat, 2, '.', ''),
            'line_price_ex_vat'      => number_format((float)$this->line_price_ex_vat, 2, '.', ''),
            'line_price_inc_vat'     => number_format($this->line_price_inc_vat, 2, '.', ''),
            'customisations'         => $this->customisations, // { option_key => selected_value }
        ];
    }
}
