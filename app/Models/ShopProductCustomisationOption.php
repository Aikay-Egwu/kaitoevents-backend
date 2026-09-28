<?php

namespace App\Models;

// ShopProductCustomisationOption — Radio / Select dropdown configuration for package size/colors.
// Each option stores JSON `choices` = [{ value, label, price_delta_ex_vat }].

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopProductCustomisationOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_id',
        'option_key',
        'label',
        'option_type',
        'is_required',
        'sort_order',
        'choices',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'sort_order'  => 'integer',
        'choices'     => 'array',
    ];

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }

    /**
     * Append VAT-inclusive price deltas to each choice for the front-end (price_delta_inc_vat = delta * 1.2).
     */
    public function getChoicesWithVatAttribute(): array
    {
        $raw = $this->choices ?? [];

        return array_map(function ($choice) {
            $deltaEx = (float)($choice['price_delta_ex_vat'] ?? 0);

            return array_merge($choice, [
                'price_delta_inc_vat' => number_format(round($deltaEx * 1.2, 2), 2, '.', ''),
                'price_delta_inc_vat_raw' => round($deltaEx * 1.2, 2),
            ]);
        }, $raw);
    }
}
