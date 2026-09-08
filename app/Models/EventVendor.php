<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

class EventVendor extends Pivot
{
    /** @use HasFactory<\Database\Factories\EventVendorFactory> */
    use HasFactory;

    protected $table = 'event_vendors';

    protected $casts = [
        'service_start' => 'datetime',
        'service_end' => 'datetime',
        'agreed_price' => 'decimal:2',
        'advance_paid' => 'decimal:2',
        'balance_due' => 'decimal:2',
    ];
}
