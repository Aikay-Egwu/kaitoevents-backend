<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'prop_id',
        'quantity',
        'price_per_day',
        'days',
        'total_price',
        'notes'
    ];

    protected $casts = [
        'price_per_day' => 'decimal:2',
        'total_price' => 'decimal:2'
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function prop()
    {
        return $this->belongsTo(Prop::class);
    }
}
