<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
     use HasFactory, SoftDeletes;

    protected $fillable = [
        'booking_number',
        'customer_id',
        'event_date',
        'return_date',
        'setup_time',
        'delivery_address',
        'delivery_city',
        'delivery_postcode',
        'contact_phone',
        'event_type',
        'special_instructions',
        'subtotal',
        'tax_amount',
        'delivery_fee',
        'setup_fee',
        'total_amount',
        'status',
        'payment_status',
        'payment_method',
        'deposit_amount',
        'deposit_paid_at',
        'confirmed_at',
        'delivered_at',
        'returned_at'
    ];

    protected $casts = [
        'event_date' => 'date',
        'return_date' => 'date',
        'setup_time' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'setup_fee' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'deposit_paid_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'delivered_at' => 'datetime',
        'returned_at' => 'datetime'
    ];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function bookingItems()
    {
        return $this->hasMany(BookingItem::class);
    }

    public function generateBookingNumber()
    {
        return 'BK' . date('Y') . str_pad($this->id, 6, '0', STR_PAD_LEFT);
    }

    public function canBeCancelled()
    {
        return $this->status === 'pending' || $this->status === 'confirmed';
    }

    public function getStatusColorAttribute()
    {
        return match($this->status) {
            'pending' => 'yellow',
            'confirmed' => 'blue',
            'delivered' => 'green',
            'completed' => 'gray',
            'cancelled' => 'red',
            default => 'gray'
        };
    }
}
