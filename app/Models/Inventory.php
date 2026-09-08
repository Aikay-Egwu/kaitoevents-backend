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
}
