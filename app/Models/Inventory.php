<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inventory extends Model
{
    /** @use HasFactory<\Database\Factories\InventoryFactory> */
    use HasFactory;

    /**
     * All 28 fillable fields — original + 18 new columns from the Stock Asset Register Excel.
     */
    protected $fillable = [
        'asset_id',
        'name',
        'inventory_category_id',
        'price',
        'cost_price',
        'description',
        'color',
        'unit',
        'condition',
        'unit_replacement_cost',
        'current_value',
        'purchase_date',
        'last_checked',
        'location_name',
        'location_zone',
        'supplier',
        'pat_service_due',
        'events_used_count',
        'notes',
        'total_revenue_generated',
        'break_even_events',
        'profit_deficit',
        'total_quantity',
        'quantity_available',
        'is_active',
        // Shop-facing fields (added by 2026_09_22_000005_add_shop_fields_to_inventory_tables)
        'slug',
        'tagline',
        'shop_price',
        'for_shop',
        'suitable_for_guests_min',
        'suitable_for_guests_max',
        'suitable_for_guests_label',
    ];

    protected $casts = [
        'price'                   => 'decimal:2',
        'cost_price'              => 'decimal:2',
        'unit_replacement_cost'   => 'decimal:2',
        'current_value'           => 'decimal:2',
        'total_revenue_generated' => 'decimal:2',
        'profit_deficit'          => 'decimal:2',
        'purchase_date'           => 'date',
        'last_checked'            => 'date',
        'pat_service_due'         => 'date',
        'is_active'               => 'boolean',
        'quantity_available'      => 'integer',
        'total_quantity'          => 'integer',
        'events_used_count'       => 'integer',
        'break_even_events'       => 'integer',
        'shop_price'              => 'decimal:2',
        'for_shop'                => 'boolean',
        'suitable_for_guests_min' => 'integer',
        'suitable_for_guests_max' => 'integer',
    ];

    /**
     * Events this inventory is assigned to via the pivot table.
     */
    public function events()
    {
        return $this->belongsToMany(Event::class, 'event_inventories')
                    ->withPivot('quantity')
                    ->withTimestamps();
    }

    /**
     * Parent InventoryCategory.
     */
    public function category()
    {
        return $this->belongsTo(InventoryCategory::class, 'inventory_category_id');
    }

    /**
     * Event usage logs — each deployment/return of the asset to/from an event.
     */
    public function usageLogs(): HasMany
    {
        return $this->hasMany(EventUsageLog::class, 'inventory_id');
    }

    /**
     * Repair & maintenance logs for this asset (PAT, fixes, servicing, retirement).
     */
    public function repairLogs(): HasMany
    {
        return $this->hasMany(RepairMaintenanceLog::class, 'inventory_id');
    }

    /**
     * Legacy location string accessor — used by existing inventory-tab.tsx and for
     * backward-compatible API responses while new location_name/location_zone split
     * is rolled out across all UI.
     */
    public function getLocationDisplayAttribute(): string
    {
        $name = trim($this->location_name ?? '');
        $zone = trim($this->location_zone ?? '');

        if ($name === '' && $zone === '') {
            return '';
        }

        if ($zone === '') {
            return $name;
        }

        return $name . ' — ' . $zone;
    }

    /**
     * Human-readable label for the 4-way condition enum (mapped from Dashboard legend).
     * Uses native DB machine keys → display labels.
     */
    public function getConditionLabelAttribute(): string
    {
        return match ($this->condition) {
            'excellent_good'       => 'Excellent / Good',
            'fair_wear'            => 'Fair / Wear',
            'needs_repair'         => 'Needs Repair',
            'retired_written_off'  => 'Retired / Written Off',
            default                => 'Unknown',
        };
    }

    /**
     * Availability predicate — extended to account for retired condition and total qty.
     * Retired items are never "available" regardless of is_active flag.
     */
    public function isAvailable($requestedQuantity = 1)
    {
        if ($this->condition === 'retired_written_off') {
            return false;
        }

        return $this->is_active
            && $this->quantity_available >= $requestedQuantity;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAvailable($query)
    {
        return $query->where('quantity_available', '>', 0);
    }

    /**
     * Filter by the 4-way condition enum (excellent_good, fair_wear, needs_repair, retired_written_off).
     */
    public function scopeByCondition($query, string $condition)
    {
        return $query->where('condition', $condition);
    }

    /**
     * Filter by supplier (case-insensitive partial match on supplier name).
     */
    public function scopeBySupplier($query, string $supplier)
    {
        return $query->where('supplier', 'like', "%{$supplier}%");
    }

    // ---------------------------------------------------------------------
    // Shop-facing surface — this model is the source of shop products.
    // ---------------------------------------------------------------------

    /**
     * Shop gallery images — primary first, then by sort_order.
     */
    public function images(): HasMany
    {
        return $this->hasMany(ShopProductImage::class, 'inventory_id')
                    ->orderByDesc('is_primary')
                    ->orderBy('sort_order');
    }

    /**
     * Shop customisation options — ordered by sort_order.
     */
    public function customisationOptions(): HasMany
    {
        return $this->hasMany(ShopProductCustomisationOption::class, 'inventory_id')
                    ->orderBy('sort_order');
    }

    /**
     * Approved-only shop reviews — newest first.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(ShopProductReview::class, 'inventory_id')
                    ->where('is_approved', true)
                    ->orderByDesc('review_date');
    }

    /**
     * All reviews including unapproved — used by admin CRUD.
     */
    public function allReviews(): HasMany
    {
        return $this->hasMany(ShopProductReview::class, 'inventory_id');
    }

    /**
     * Shop price with 20% UK VAT applied, formatted to 2dp.
     */
    public function getPriceIncVatAttribute(): string
    {
        return number_format(round((float) $this->shop_price * 1.2, 2), 2, '.', '');
    }

    public function getPriceIncVatRawAttribute(): float
    {
        return round((float) $this->shop_price * 1.2, 2);
    }

    /**
     * Items explicitly flagged for sale in the shop.
     */
    public function scopeForShop($query)
    {
        return $query->where('for_shop', true);
    }

    /**
     * Filter by shop category slugs. Matches items in the named category or,
     * because inventory_categories is a 2-level hierarchy, items in any of its
     * child categories when a parent slug is given.
     *
     * @param  string[]  $slugs
     */
    public function scopeInCategorySlugs($query, array $slugs)
    {
        if (count($slugs) === 0) {
            return $query;
        }

        return $query->whereHas('category', function ($sub) use ($slugs) {
            $sub->whereIn('slug', $slugs)
                ->orWhereHas('parent', function ($parent) use ($slugs) {
                    $parent->whereIn('slug', $slugs);
                });
        });
    }

    /**
     * Keyword search across name/tagline/description (case-insensitive LIKE).
     */
    public function scopeSearch($query, string $keyword)
    {
        $kw = '%' . mb_strtolower(trim($keyword)) . '%';

        return $query->where(function ($nested) use ($kw) {
            $nested->whereRaw('LOWER(name) LIKE ?', [$kw])
                   ->orWhereRaw('LOWER(tagline) LIKE ?', [$kw])
                   ->orWhereRaw('LOWER(description) LIKE ?', [$kw]);
        });
    }

    /**
     * Shop price band filter (ex-VAT bounds, inclusive with a small epsilon).
     */
    public function scopeShopPriceBetween($query, ?float $min, ?float $max)
    {
        if ($min !== null) {
            $query->where('shop_price', '>=', $min - 0.0001);
        }
        if ($max !== null) {
            $query->where('shop_price', '<=', $max + 0.0001);
        }

        return $query;
    }

    /**
     * Auto-generate a unique slug for new catalogue entries, and clean up
     * gallery files when an item is removed.
     */
    protected static function booted(): void
    {
        static::creating(function (self $item) {
            if (empty($item->slug)) {
                $item->slug = self::generateUniqueSlug((string) $item->name);
            }
        });

        static::deleting(function (self $item) {
            /** @var \App\Services\ShopImageUploadService $svc */
            $svc = app(\App\Services\ShopImageUploadService::class);
            $svc->deleteAllForProduct($item);
        });
    }

    public static function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = \Illuminate\Support\Str::slug($name) ?: 'item';
        $slug = $base;
        $suffix = 2;

        while (self::where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '<>', $ignoreId))
            ->exists()) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }
}
