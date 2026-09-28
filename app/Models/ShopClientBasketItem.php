<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopClientBasketItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_client_basket_id',
        'inventory_id',
        'quantity',
        'selected_options',
        'unit_price_ex_vat',
        'variant_label',
        'line_key',
    ];

    protected $casts = [
        'selected_options' => 'array',
        'unit_price_ex_vat' => 'decimal:2',
        'quantity' => 'integer',
    ];

    public function basket(): BelongsTo
    {
        return $this->belongsTo(ShopClientBasket::class, 'shop_client_basket_id');
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }

    public static function buildLineKey(int $productId, ?array $selectedOptions): string
    {
        if (empty($selectedOptions)) {
            return (string) $productId;
        }

        ksort($selectedOptions);
        $parts = [];
        foreach ($selectedOptions as $key => $value) {
            $parts[] = "{$key}={$value}";
        }

        return $productId . '::' . implode('|', $parts);
    }
}
