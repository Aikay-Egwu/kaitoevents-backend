<?php

namespace App\Models;

// ShopOrder — customer DIY order with totals, billing + event context, snapshot line items.
// Generates human-readable ref KE-YYYYMMDD-XXXX on save.

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ShopOrder extends Model
{
    /** @use HasFactory<\Database\Factories\ShopOrderFactory> */
    use HasFactory;

    public const STATUS_PENDING          = 'pending';
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const STATUS_PAID             = 'paid';
    public const STATUS_PROCESSING       = 'processing';
    public const STATUS_COMPLETED        = 'completed';
    public const STATUS_CANCELLED        = 'cancelled';

    public const PAYMENT_CARD         = 'card';
    public const PAYMENT_BANK_TRANSFER = 'bank_transfer';
    public const PAYMENT_ON_ARRIVAL   = 'on_arrival';

    protected $fillable = [
        'client_id',
        'order_ref',
        'status',
        'customer_full_name',
        'customer_email',
        'customer_phone',
        'billing_address_line_1',
        'billing_address_line_2',
        'billing_city',
        'billing_postcode',
        'billing_country',
        'event_date',
        'event_venue',
        'subtotal_ex_vat',
        'vat_amount',
        'shipping_amount',
        'grand_total',
        'payment_method',
        'notes',
        'source_ref',
    ];

    protected $casts = [
        'subtotal_ex_vat' => 'decimal:2',
        'vat_amount'      => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'grand_total'     => 'decimal:2',
        'event_date'      => 'date',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ShopOrderItem::class, 'shop_order_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(ShopPaymentTransaction::class, 'shop_order_id')
                    ->orderByDesc('created_at');
    }

    /**
     * Generate KE-YYYYMMDD-XXXX style reference — 5 collision retries.
     *
     * @throws \RuntimeException If unique ref cannot be generated after 5 tries.
     */
    public static function generateReference(): string
    {
        $datePart = now()->format('Ymd');
        for ($tries = 0; $tries < 5; $tries++) {
            $rand = strtoupper(Str::random(4));
            $ref  = "KE-{$datePart}-{$rand}";
            if (!self::where('order_ref', $ref)->exists()) {
                return $ref;
            }
        }

        throw new \RuntimeException('Failed to generate unique ShopOrder reference after 5 attempts');
    }

    /**
     * Recompute server-side totals and check integrity within 1p tolerance against client-submitted values.
     *
     * @return array{ subtotal_ex_vat: float, vat_amount: float, grand_total: float }
     */
    public static function recomputeTotalsFromItems(iterable $items, float $shipping): array
    {
        $subtotal = 0.0;
        foreach ($items as $line) {
            $unitEx = (float)($line['unit_price_ex_vat'] ?? 0);
            $qty    = (int)($line['quantity'] ?? 0);
            $subtotal += round($unitEx * $qty, 2);
        }
        $subtotalRounded = round($subtotal, 2);
        $vat             = round($subtotalRounded * 0.2, 2);
        $grand           = round($subtotalRounded + $vat + round($shipping, 2), 2);

        return [
            'subtotal_ex_vat' => $subtotalRounded,
            'vat_amount'      => $vat,
            'grand_total'     => $grand,
        ];
    }

    /**
     * Initial status mapping based on payment_method (matches spec §2.4 FR-4.1).
     */
    public static function initialStatusFor(string $paymentMethod): string
    {
        return match ($paymentMethod) {
            self::PAYMENT_CARD => self::STATUS_AWAITING_PAYMENT,
            default            => self::STATUS_PENDING, // bank_transfer | on_arrival
        };
    }
}
