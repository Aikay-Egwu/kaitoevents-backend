<?php

namespace App\Models;

// ShopPaymentTransaction — stores processor REFERENCES only (pi_xxx / UUID).
// FILLABLE WHITELIST — does NOT include raw PAN / CVC / expiry fields. PCI SAQ-A compliant.

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopPaymentTransaction extends Model
{
    public const STATUS_CREATED   = 'created';
    public const STATUS_PENDING   = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_REFUNDED  = 'refunded';

    // IMPORTANT: fillable whitelist — never include card_number, cvc, cvv, expiry, pan, track data.
    protected $fillable = [
        'shop_order_id',
        'transaction_ref',
        'provider',
        'provider_payment_id',
        'status',
        'amount',
        'currency',
        'raw_metadata',
        'failure_reason',
        'processed_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'raw_metadata' => 'array',
        'processed_at' => 'datetime',
    ];

    use HasFactory;

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopOrder::class, 'shop_order_id');
    }
}
