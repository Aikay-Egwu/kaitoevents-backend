<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class ShopClientBasket extends Model
{
    use HasFactory;

    public const VAT_RATE = 0.2;
    public const SHIPPING_FLAT = 15.00;
    public const FREE_SHIPPING_THRESHOLD = 300.00;

    public const STATUS_OPEN = 'open';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_ABANDONED = 'abandoned';

    protected $fillable = [
        'client_id',
        'status',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShopClientBasketItem::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public static function getOrCreateFor(Client $client): self
    {
        return static::firstOrCreate(
            ['client_id' => $client->id, 'status' => self::STATUS_OPEN]
        );
    }

    public function computeTotals(): array
    {
        $subtotal = round($this->items->sum(fn ($item) => $item->unit_price_ex_vat * $item->quantity), 2);
        $vat = round($subtotal * self::VAT_RATE, 2);
        $shipping = $subtotal >= self::FREE_SHIPPING_THRESHOLD ? 0.00 : self::SHIPPING_FLAT;
        $grandTotal = round($subtotal + $vat + $shipping, 2);

        return [
            'subtotal_ex_vat' => $subtotal,
            'vat_amount' => $vat,
            'shipping_amount' => $shipping,
            'grand_total' => $grandTotal,
            'items_count' => $this->items->sum('quantity'),
        ];
    }
}
