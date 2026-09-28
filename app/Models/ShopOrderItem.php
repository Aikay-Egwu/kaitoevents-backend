<?php

namespace App\Models;

// ShopOrderItem — immutable line-item snapshot within a ShopOrder.
// `inventory_id` is set null on inventory deletion so order history is preserved; snapshot_* columns always contain values.

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_order_id',
        'inventory_id',
        'snapshot_name',
        'snapshot_slug',
        'snapshot_variant_label',
        'unit_price_ex_vat',
        'quantity',
        'line_price_ex_vat',
        'customisations',
    ];

    protected $casts = [
        'unit_price_ex_vat' => 'decimal:2',
        'quantity'          => 'integer',
        'line_price_ex_vat' => 'decimal:2',
        'customisations'    => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopOrder::class, 'shop_order_id');
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }

    public function getLinePriceIncVatAttribute(): float
    {
        return round((float)$this->line_price_ex_vat * 1.2, 2);
    }

    public function getUnitPriceIncVatAttribute(): float
    {
        return round((float)$this->unit_price_ex_vat * 1.2, 2);
    }
}
