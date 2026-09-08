<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCategory extends Model
{
    /** @use HasFactory<\Database\Factories\InfentoryCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'category_name',
        'category_description',
        'category_image',
    ];

    /**
     * Parent category (if this is a child "Category / Type" subcategory).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Child "Category / Type" subcategories (if this is a root/parent from the Dashboard).
     * Loads children recursively one level deep — Excel uses exactly 2 levels.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->with('children');
    }

    /**
     * Direct child count — for hierarchical listing display.
     */
    public function directChildren(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Inventory items that belong directly to this category.
     */
    public function inventoryItems(): HasMany
    {
        return $this->hasMany(Inventory::class, 'inventory_category_id');
    }

    /**
     * Scope to root/parent-level categories (parent_id is null).
     */
    public function scopeRootCategories($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope to child/sub-level categories (parent_id is NOT null).
     */
    public function scopeChildCategories($query)
    {
        return $query->whereNotNull('parent_id');
    }

    /**
     * Eager-load 2-level hierarchy: root → children (2 levels is the max used by Excel).
     */
    public function scopeWithHierarchy($query)
    {
        return $query->with(['children', 'parent']);
    }

    /**
     * Inventory count including all descendants (for display / stats).
     * For 2-level hierarchy, this counts items of a parent's children.
     */
    public function getTotalInventoryCountAttribute(): int
    {
        if ($this->parent_id === null) {
            $childIds = $this->children()->pluck('id')->all();
            $selfItems = $this->inventoryItems()->count();
            $childItems = Inventory::whereIn('inventory_category_id', $childIds)->count();

            return $selfItems + $childItems;
        }

        return $this->inventoryItems()->count();
    }
}
