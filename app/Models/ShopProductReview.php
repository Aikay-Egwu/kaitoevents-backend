<?php

namespace App\Models;

// ShopProductReview — moderated 1..5 star review per inventory item.

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopProductReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_id',
        'author_name',
        'rating',
        'title',
        'review_text',
        'review_date',
        'is_approved',
    ];

    protected $casts = [
        'rating'      => 'integer',
        'review_date' => 'date',
        'is_approved' => 'boolean',
    ];

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }
}
