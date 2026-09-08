<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Service extends Model
{
    /** @use HasFactory<\Database\Factories\ServiceFactory> */
    use HasFactory;
    protected $fillable = [
        "name","price","description"
    ];
    /* protected $fillable = [
        'service_name',
        'service_description',
        'price',
        'duration_hours',
        'is_active',
        'service_category_id',
        'max_capacity',
        'min_capacity',
        'requires_advance_notice',
        'advance_notice_hours',
        'setup_time_hours', 
        'cleanup_time_hours',
        'equipment_required',
        'staff_required',
        'location_restrictions',
        'seasonal_availability',

        'discount_eligible',
        'service_image',
        'service_tags',
    ]; */

    protected $casts = [
        'price' => 'decimal:2',
        'duration_hours' => 'decimal:2',
        'setup_time_hours' => 'decimal:2',
        'cleanup_time_hours' => 'decimal:2',
        'is_active' => 'boolean',
        'requires_advance_notice' => 'boolean',
        'discount_eligible' => 'boolean',
        'equipment_required' => 'array',
        'staff_required' => 'array',
        'location_restrictions' => 'array',
        'seasonal_availability' => 'array',
        'service_tags' => 'array',
    ];

    // Relationships
    public function events()
    {
        return $this->belongsToMany(Event::class, 'event_services')
                    ->withPivot([
                        'quantity', 
                        'price', 
                        'value',
                        'notes', 
                        'scheduled_date', 
                        'scheduled_time', 
                        'duration_hours',
                        'status',
                        'assigned_by',
                        'assigned_at'
                    ])
                    ->withTimestamps();
    }

    public function category()
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function eventTypes()
    {
        return $this->belongsToMany(EventType::class, 'service_event_types');
    }

    public function prerequisites()
    {
        return $this->belongsToMany(Service::class, 'service_prerequisites', 'service_id', 'prerequisite_service_id');
    }

    public function dependentServices()
    {
        return $this->belongsToMany(Service::class, 'service_prerequisites', 'prerequisite_service_id', 'service_id');
    }

    public function complementaryServices()
    {
        return $this->belongsToMany(Service::class, 'service_complements', 'service_id', 'complementary_service_id');
    }

    public function serviceOptions()
    {
        return $this->hasMany(ServiceOption::class); 
    }

    public function packages()
    {
        return $this->belongsToMany(ServicePackage::class, 'package_services')
                    ->withPivot('quantity', 'discount_percentage');
    }

    // Scopes
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory(Builder $query, int $categoryId): Builder
    {
        return $query->where('service_category_id', $categoryId);
    }

    public function scopeByPriceRange(Builder $query, float $minPrice, float $maxPrice = null): Builder
    {
        $query->where('price', '>=', $minPrice);
        
        if ($maxPrice) {
            $query->where('price', '<=', $maxPrice);
        }
        
        return $query;
    }

    public function scopeByDuration(Builder $query, float $minHours, float $maxHours = null): Builder
    {
        $query->where('duration_hours', '>=', $minHours);
        
        if ($maxHours) {
            $query->where('duration_hours', '<=', $maxHours);
        }
        
        return $query;
    }

    public function scopeForEventType(Builder $query, int $eventTypeId): Builder
    {
        return $query->whereHas('eventTypes', function ($q) use ($eventTypeId) {
            $q->where('event_type_id', $eventTypeId);
        });
    }

    public function scopeAvailableForCapacity(Builder $query, int $guestCount): Builder
    {
        return $query->where(function ($q) use ($guestCount) {
            $q->whereNull('max_capacity')
              ->orWhere('max_capacity', '>=', $guestCount);
        })->where(function ($q) use ($guestCount) {
            $q->whereNull('min_capacity')
              ->orWhere('min_capacity', '<=', $guestCount);
        });
    }

    public function scopeRequiringAdvanceNotice(Builder $query): Builder
    {
        return $query->where('requires_advance_notice', true);
    }

    public function scopeDiscountEligible(Builder $query): Builder
    {
        return $query->where('discount_eligible', true);
    }


    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function ($q) use ($search) {
            $q->where('service_name', 'like', "%{$search}%")
              ->orWhere('service_description', 'like', "%{$search}%")
              ->orWhereJsonContains('service_tags', $search);
        });
    }

    // Business Logic Methods
    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function isAvailableForEvent(Event $event): bool
    {
        // Check if service is active
        if (!$this->isActive()) {
            return false;
        }

        // Check capacity constraints
        if ($event->guest_number) {
            if ($this->max_capacity && $event->guest_number > $this->max_capacity) {
                return false;
            }
            
            if ($this->min_capacity && $event->guest_number < $this->min_capacity) {
                return false;
            }
        }

        // Check event type compatibility
        if ($this->eventTypes()->exists()) {
            if (!$this->eventTypes()->where('event_type_id', $event->event_type_id)->exists()) {
                return false;
            }
        }

        // Check seasonal availability
        if ($this->seasonal_availability && $event->event_date) {
            $eventMonth = $event->event_date->month;
            if (!in_array($eventMonth, $this->seasonal_availability)) {
                return false;
            }
        }

        return true;
    }

    public function calculatePriceForEvent(Event $event, int $quantity = 1, ?float $customPrice = null): float
    {
        if ($customPrice !== null) {
            return $customPrice * $quantity;
        }

        $basePrice = $this->price;
        
        // Apply capacity-based pricing adjustments
        if ($event->guest_number ) {
            if ($event->guest_number > 100) {
                $basePrice *= 1.2; // 20% increase for large events
            } elseif ($event->guest_number < 25) {
                $basePrice *= 0.9; // 10% discount for small events
            }
        }

        // Apply seasonal pricing
        if ($event->event_date && $this->seasonal_availability) {
            $eventMonth = $event->event_date->month;
            $peakMonths = [6, 7, 8, 12]; // Summer and December
            
            if (in_array($eventMonth, $peakMonths)) {
                $basePrice *= 1.15; // 15% peak season surcharge
            }
        }

        return $basePrice * $quantity;
    }

    public function getTotalDurationWithSetup(): float
    {
        return $this->duration_hours + 
               ($this->setup_time_hours ?? 0) + 
               ($this->cleanup_time_hours ?? 0);
    }

    public function getAdvanceNoticeRequired(): int
    {
        return $this->requires_advance_notice ? ($this->advance_notice_hours ?? 24) : 0;
    }

    public function hasPrerequisites(): bool
    {
        return $this->prerequisites()->exists();
    }

    public function getPrerequisiteServices(): array
    {
        return $this->prerequisites()->pluck('service_name', 'id')->toArray();
    }

    public function hasComplementaryServices(): bool
    {
        return $this->complementaryServices()->exists();
    }

    public function getComplementaryServices(): array
    {
        return $this->complementaryServices()->pluck('service_name', 'id')->toArray();
    }

    public function isCompatibleWith(Service $otherService): bool
    {
        // Check if services can be assigned together
        // This could be based on equipment conflicts, staff conflicts, etc.
        
        if ($this->equipment_required && $otherService->equipment_required) {
            $thisEquipment = $this->equipment_required;
            $otherEquipment = $otherService->equipment_required;
            
            // Check for equipment conflicts
            $conflicts = array_intersect($thisEquipment, $otherEquipment);
            if (!empty($conflicts)) {
                return false;
            }
        }

        return true;
    }

    public function canBeScheduledAt(\DateTime $dateTime, float $duration): bool
    {
        // Check if service can be scheduled at the given time
        // This would typically check against existing bookings
        
        // For now, just check if it's within business hours
        $hour = (int) $dateTime->format('H');
        
        if ($hour < 8 || $hour > 22) {
            return false; // Outside business hours
        }

        return true;
    }

    public function getEstimatedRevenue(): float
    {
        return $this->events()
                   ->wherePivot('status', '!=', 'cancelled')
                   ->sum('event_services.price');
    }

    public function getBookingCount(): int
    {
        return $this->events()
                   ->wherePivot('status', '!=', 'cancelled')
                   ->count();
    }

    public function getAverageRating(): ?float
    {
        // This would require a service_ratings table
        // For now, return null
        return null;
    }

    public function isPopular(): bool
    {
        return $this->getBookingCount() > 10; // Arbitrary threshold
    }

    public function getDiscountedPrice(float $discountPercentage): float
    {
        if (!$this->discount_eligible) {
            return $this->price;
        }

        return $this->price * (1 - ($discountPercentage / 100));
    }



    public function getTotalPriceWithTax(float $baseAmount = null, float $taxRate = 0.08): float
    {
        $amount = $baseAmount ?? $this->price;
        $taxAmount = $amount * $taxRate;
        return $amount + $taxAmount;
    }

    public function getTaxAmount(float $baseAmount = null, float $taxRate = 0.08): float
    {
        $amount = $baseAmount ?? $this->price;
        return $amount * $taxRate;
    }

    // Event Listeners
    protected static function booted(): void
    {
        static::creating(function (Service $service) {
            // Set default values if needed
            // Add any default value logic here
        });

        static::updating(function (Service $service) {
            // Log price changes for audit trail
            if ($service->isDirty('price')) {
                \Log::info('Service price changed', [
                    'service_id' => $service->id,
                    'old_price' => $service->getOriginal('price'),
                    'new_price' => $service->price,
                ]);
            }
        });
    }
}