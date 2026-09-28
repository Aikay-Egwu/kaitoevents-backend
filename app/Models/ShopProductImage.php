<?php

namespace App\Models;

// ShopProductImage — gallery image for an Inventory item; stored on `public` disk at shop/products/{id}/{uuid}.ext

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

class ShopProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_id',
        'path',
        'alt_text',
        'sort_order',
        'is_primary',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_primary' => 'boolean',
    ];

    protected $appends = [
        'url',
    ];

    /**
     * Absolute URL for the image — resolved via Storage::disk('public').
     */
    public function getUrlAttribute(): ?string
    {
        if ($this->path === null || $this->path === '') {
            return null;
        }
        if (str_starts_with($this->path, 'http://') || str_starts_with($this->path, 'https://')) {
            return $this->path;
        }

        /** @var FilesystemAdapter $disk Storage::disk() is docblocked as the contract, which omits url(). */
        $disk = Storage::disk('public');

        return $disk->url($this->path);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }
}
