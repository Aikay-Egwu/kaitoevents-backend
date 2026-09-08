<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Prop extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'category_id',
        'price_per_day',
        'quantity_available',
        'quantity_total',
        'dimensions',
        'weight',
        'setup_instructions',
        'is_active',
        'requires_setup'
    ];

    protected $casts = [
        'price_per_day' => 'decimal:2',
        'is_active' => 'boolean',
        'requires_setup' => 'boolean',
        'dimensions' => 'array'
    ];

    public function category()
    {
        return $this->belongsTo(PropCategory::class);
    }

    public function images()
    {
        return $this->hasMany(PropImage::class);
    }

    public function bookingItems()
    {
        return $this->hasMany(BookingItem::class);
    }

    public function getAvailableQuantityAttribute()
    {
        return $this->quantity_available;
    }

    public function isAvailable($quantity = 1, $startDate = null, $endDate = null)
    {
        if ($this->quantity_available < $quantity) {
            return false;
        }

        if ($startDate && $endDate) {
            $bookedQuantity = $this->bookingItems()
                ->whereHas('booking', function ($query) use ($startDate, $endDate) {
                    $query->where('status', '!=', 'cancelled')
                          ->where(function ($q) use ($startDate, $endDate) {
                              $q->whereBetween('event_date', [$startDate, $endDate])
                                ->orWhereBetween('return_date', [$startDate, $endDate])
                                ->orWhere(function ($subq) use ($startDate, $endDate) {
                                    $subq->where('event_date', '<=', $startDate)
                                         ->where('return_date', '>=', $endDate);
                                });
                          });
                })
                ->sum('quantity');

            return ($this->quantity_available - $bookedQuantity) >= $quantity;
        }

        return true;
    }
}
