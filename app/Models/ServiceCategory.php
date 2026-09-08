<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class ServiceCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_name',
        'category_description',
        'category_image',
        'sort_order',
        'is_active',
        'parent_category_id',
        'category_color',
        'category_icon',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    // Relationships
    public function services()
    {
        return $this->hasMany(Service::class, 'service_category_id');
    }

    public function parentCategory()
    {
        return $this->belongsTo(ServiceCategory::class, 'parent_category_id');
    }

    public function childCategories()
    {
        return $this->hasMany(ServiceCategory::class, 'parent_category_id');
    }

    // Scopes
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeParentCategories(Builder $query): Builder
    {
        return $query->whereNull('parent_category_id');
    }

    public function scopeChildCategories(Builder $query): Builder
    {
        return $query->whereNotNull('parent_category_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('category_name');
    }

    // Business Logic Methods
    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function hasServices(): bool
    {
        return $this->services()->exists();
    }

    public function getActiveServicesCount(): int
    {
        return $this->services()->active()->count();
    }

    public function isParentCategory(): bool
    {
        return $this->parent_category_id === null;
    }

    public function hasChildCategories(): bool
    {
        return $this->childCategories()->exists();
    }

    public function getAllServices()
    {
        $services = $this->services();
        
        // Include services from child categories
        if ($this->hasChildCategories()) {
            $childCategoryIds = $this->childCategories()->pluck('id');
            $services = Service::whereIn('service_category_id', $childCategoryIds->push($this->id));
        }
        
        return $services;
    }

    public function getAverageServicePrice(): float
    {
        return $this->services()->avg('price') ?? 0;
    }

    public function getTotalRevenue(): float
    {
        return $this->services()
                   ->join('event_services', 'services.id', '=', 'event_services.service_id')
                   ->sum('event_services.price');
    }
}