<?php

namespace App\Models;

use App\Models\EventAestheticSelection;
use App\Models\RestrictionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    /** @use HasFactory<\Database\Factories\EventFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'event_type_id',
        'event_name',
        'event_date',
        'start_time',
        'end_time',
        'venue_name',
        'venue_address',
        'number_of_guests',
        'event_time',
        'budget',
        'special_instructions',
        'status',
    ];

    protected $casts = [
        'event_date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    /**
     * Get and set the budget attribute.
     */
    protected function budget(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function ($value) {
                if ($value === null || trim((string)$value) === '' || !is_numeric($value)) {
                    return null;
                }
                return number_format((float)$value, 2, '.', '');
            },
            set: function ($value) {
                if ($value === null || trim((string)$value) === '' || !is_numeric($value)) {
                    return null;
                }
                return $value;
            }
        );
    }

    // Relationships
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function eventType()
    {
        return $this->belongsTo(EventType::class);
    }

    public function invoice()
    {
        return $this->hasOne(EventInvoice::class);
    }

    public function inventories()
    {
        return $this->belongsToMany(Inventory::class, 'event_inventories')
            ->withPivot('quantity', 'price')
            ->withTimestamps();
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, 'event_services')
            ->withPivot('value', 'quantity', 'price', 'notes')
            ->withTimestamps()
            // Automatically cast the JSON 'value' column to an array
            ->withCasts(['pivot.value' => 'array']);
    }
    /* public function services()
    {
        return $this->belongsToMany(Service::class, 'event_services')
            ->withPivot('quantity', 'price')
            ->withTimestamps();
    } */

    public function images()
    {
        return $this->hasMany(EventImage::class);
    }

    public function venue()
    {
        return $this->hasOne(EventVenue::class);
    }

    /**
     * Get the design concept associated with this event.
     * Each event has at most one design concept (one-to-one).
     */
    public function designConcept()
    {
        return $this->hasOne(EventDesignConcept::class);
    }

    /**
     * Get the client brief associated with this event.
     * Each event has at most one client brief (one-to-one).
     * Named clientBriefs for consistency with Laravel convention
     * (the underlying table is events_client_briefs).
     */
    public function clientBriefs()
    {
        return $this->hasOne(EventClientBrief::class);
    }

    // Scopes
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('event_date', '>', now()->toDateString())
            ->orWhere(function ($q) {
                $q->where('event_date', '=', now()->toDateString())
                    ->whereTime('start_time', '>', now()->toTimeString());
            });
    }

    public function scopePast(Builder $query): Builder
    {
        return $query->where('event_date', '<', now()->toDateString())
            ->orWhere(function ($q) {
                $q->where('event_date', '=', now()->toDateString())
                    ->whereTime('end_time', '<', now()->toTimeString());
            });
    }

    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', 'confirmed');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeWithinDateRange(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->whereBetween('event_date', [$startDate, $endDate]);
    }

    public function scopeByVenue(Builder $query, string $venue): Builder
    {
        return $query->where('venue_name', 'like', "%{$venue}%");
    }

    public function scopeByGuestCount(Builder $query, int $minGuests, ?int $maxGuests = null): Builder
    {
        $query->where('guest_number', '>=', $minGuests);

        if ($maxGuests) {
            $query->where('guest_number', '<=', $maxGuests);
        }

        return $query;
    }

    // Business Logic Methods
    public function calculateTotalCost(): float
    {
        $serviceCost = $this->services->sum(function ($service) {
            return $service->price * ($service->pivot->quantity ?? 1);
        });

        $inventoryCost = $this->inventories->sum(function ($inventory) {
            return $inventory->price * ($inventory->pivot->quantity ?? 1);
        });

        return $serviceCost + $inventoryCost;
    }

    public function updateTotalCost(): void
    {
        // This could update a total_cost field if it exists
        // For now, we calculate it dynamically
        $totalCost = $this->calculateTotalCost();

        // If you add a total_cost field to the events table, uncomment:
        // $this->update(['total_cost' => $totalCost]);
    }

    public function isUpcoming(): bool
    {
        if (!$this->event_date || !$this->start_time) {
            return false;
        }

        $eventDateTime = $this->event_date->format('Y-m-d') . ' ' . $this->start_time->format('H:i:s');
        return strtotime($eventDateTime) > time();
    }

    public function isPast(): bool
    {
        if (!$this->event_date || !$this->end_time) {
            return false;
        }

        $eventDateTime = $this->event_date->format('Y-m-d') . ' ' . $this->end_time->format('H:i:s');
        return strtotime($eventDateTime) < time();
    }

    public function isToday(): bool
    {
        return $this->event_date && $this->event_date->isToday();
    }

    public function getDaysUntilEvent(): ?int
    {
        if (!$this->event_date) {
            return null;
        }

        return $this->event_date->diffInDays(now(), false);
    }

    public function getHoursUntilEvent(): ?int
    {
        if (!$this->event_date || !$this->start_time) {
            return null;
        }

        $eventDateTime = $this->event_date->format('Y-m-d') . ' ' . $this->start_time->format('H:i:s');
        $eventTimestamp = strtotime($eventDateTime);

        return (int) (($eventTimestamp - time()) / 3600);
    }

    public function getDurationHours(): ?float
    {
        if (!$this->start_time || !$this->end_time) {
            return null;
        }

        $start = strtotime($this->start_time->format('H:i:s'));
        $end = strtotime($this->end_time->format('H:i:s'));

        return round(($end - $start) / 3600, 2);
    }

    public function canBeModified(): bool
    {
        return !in_array($this->status, ['completed', 'cancelled']);
    }

    public function canBeCancelled(): bool
    {
        return !in_array($this->status, ['completed', 'cancelled']);
    }

    public function canChangeDate(): bool
    {
        if (!$this->canBeModified()) {
            return false;
        }

        // Cannot change date within 48 hours for confirmed events
        if ($this->status === 'confirmed') {
            $hoursUntilEvent = $this->getHoursUntilEvent();
            return $hoursUntilEvent === null || $hoursUntilEvent > 48;
        }

        return true;
    }

    public function hasInvoice(): bool
    {
        return $this->invoice()->exists();
    }

    public function hasServices(): bool
    {
        return $this->services()->exists();
    }

    public function hasInventories(): bool
    {
        return $this->inventories()->exists();
    }

    public function hasImages(): bool
    {
        return $this->images()->exists();
    }

    public function getServicesTotal(): float
    {
        return $this->services->sum(function ($service) {
            // Prefer custom pivot price over the service's catalogue price so
            // manual adjustments flow through to the invoice total.
            $unit = $service->pivot->price ?? $service->price;
            return (float) $unit * (int) ($service->pivot->quantity ?? 1);
        });
    }

    public function getInventoriesTotal(): float
    {
        return $this->inventories->sum(function ($inventory) {
            // Use pivot->price (manually overridden or re-calculated base
            // price from last quantity change) — never the stale catalogue
            // price after a manual adjustment.
            $unit = $inventory->pivot->price ?? $inventory->price;
            return (float) $unit * (int) ($inventory->pivot->quantity ?? 1);
        });
    }

    public function getEstimatedTotal(): float
    {
        return $this->getServicesTotal() + $this->getInventoriesTotal();
    }

    public function assignService(int $serviceId, int $quantity = 1, ?float $customPrice = null): void
    {
        $pivotData = ['quantity' => $quantity];

        if ($customPrice !== null) {
            $pivotData['price'] = $customPrice;
        }

        $this->services()->attach($serviceId, $pivotData);
        $this->updateTotalCost();
    }

    public function removeService(int $serviceId): void
    {
        $this->services()->detach($serviceId);
        $this->updateTotalCost();
    }

    /**
     * Assign an inventory item to the event.
     *
     * @param int      $inventoryId id of an active, business-owned Inventory
     * @param int      $quantity    positive integer quantity (validated upstream)
     * @param float|null $customPrice When provided, overrides the unit price for
     *                              invoice calculation. When null (default), the
     *                              item's own `price` column is written into the
     *                              pivot so subsequent recalculations always have
     *                              a concrete base value to work with.
     */
    public function assignInventory(
        int $inventoryId,
        int $quantity = 1,
        ?float $customPrice = null,
    ): void {
        $unitPrice = Inventory::query()
            ->where('id', $inventoryId)
            ->value('price');

        $this->inventories()->attach($inventoryId, [
            'quantity' => $quantity,
            'price'    => $customPrice ?? (float) $unitPrice,
        ]);

        $this->updateTotalCost();
    }

    /**
     * Update an already-assigned inventory item.
     *
     * Pricing rules (mirrors the frontend behaviour, verified by PHPUnit):
     *   1. If `$quantity` is CHANGED from the current pivot value → the
     *      unit price is RESET to the inventory's catalogue price, which
     *      discards any prior manual override (this is the "restore base
     *      calculation on qty edit" requirement).
     *   2. If only `$customPrice` is provided with the SAME quantity → the
     *      manual override is saved/persisted untouched.
     *   3. If both are provided → quantity takes precedence (rule 1 runs),
     *      unless `$customPrice` is explicitly sent (then rule 2 is applied
     *      AFTER rule 1). This matches the UI pattern where editing qty in
     *      the dialog blanks custom_price first, and the user may re-apply
     *      a new override in the same form submit.
     *
     * @param int       $inventoryId
     * @param int|null  $quantity
     * @param float|null $customPrice Pass `null` to keep current price unchanged
     *                                (unless qty changed, see rule 1 above).
     *                                Pass a float to write a manual override.
     *
     * @return array{quantity:int,unit_price:float,was_reset:bool} pivot state
     *               after the update — `was_reset` signals that a prior manual
     *               override was discarded because quantity changed.
     */
    public function updateInventory(
        int $inventoryId,
        ?int $quantity = null,
        ?float $customPrice = null,
    ): array {
        $current = $this->inventories()
            ->where('inventory_id', $inventoryId)
            ->first();

        if (!$current) {
            throw new \OutOfBoundsException("Inventory #{$inventoryId} is not assigned to this event.");
        }

        $oldQty      = (int) ($current->pivot->quantity ?? 1);
        $oldPivotPrice = (float) ($current->pivot->price ?? $current->price);
        $cataloguePrice = (float) $current->price;

        $newQty = $quantity ?? $oldQty;
        $quantityChanged = $newQty !== $oldQty;

        // Decide the unit price to persist in the pivot row.
        $wasReset = false;
        if ($quantityChanged && $customPrice === null) {
            // Rule 1: qty changed, no replacement custom_price → reset
            $unitPrice = $cataloguePrice;
            $wasReset  = true;
        } elseif ($customPrice !== null) {
            // Rule 2: manual override supplied (with or without qty change)
            $unitPrice = $customPrice;
        } else {
            // No meaningful changes — keep existing pivot price (preserves
            // either a prior manual override OR a prior catalogue value).
            $unitPrice = $oldPivotPrice;
        }

        $this->inventories()->updateExistingPivot($inventoryId, [
            'quantity' => $newQty,
            'price'    => $unitPrice,
        ]);

        $this->updateTotalCost();

        return [
            'quantity'   => $newQty,
            'unit_price' => $unitPrice,
            'was_reset'  => $wasReset,
        ];
    }

    public function removeInventory(int $inventoryId): void
    {
        $this->inventories()->detach($inventoryId);
        $this->updateTotalCost();
    }

    public function updateStatus(string $newStatus): bool
    {
        $validTransitions = [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['in_progress', 'cancelled'],
            'in_progress' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
        ];

        $currentStatus = $this->status;

        if (!in_array($newStatus, $validTransitions[$currentStatus] ?? [])) {
            return false;
        }

        $this->update(['status' => $newStatus]);
        return true;
    }

    // Event Listeners
    protected static function booted(): void
    {
        static::creating(function (Event $event) {
            // Set default status if not provided
            if (!$event->status) {
                $event->status = 'pending';
            }
        });

        static::updating(function (Event $event) {
            // Update total cost when services or inventories change
            if ($event->isDirty(['status']) && in_array($event->status, ['confirmed', 'completed'])) {
                $event->updateTotalCost();
            }
        });
    }

    public function aestheticSelections()
    {
        return $this->hasMany(EventAestheticSelection::class);
    }

    public function restrictions()
    {
        return $this->belongsToMany(RestrictionType::class, 'event_venue_restrictions')
            ->withPivot('venue_position', 'impact_on_design')
            ->withTimestamps();
    }

    public function colorPalettes()
    {
        return $this->hasMany(EventColorPalette::class);
    }

    /**
     * Get all task/item tracking records for this event.
     * Used by the consultation tab to manage event planning to-do lists.
     */
    public function trackingItems()
    {
        return $this->hasMany(EventTrackingItem::class);
    }

    /**
     * All job groups / teams assigned to this event.
     * Groups hold members (staff users) and contain individual tasks.
     */
    public function jobGroups()
    {
        return $this->hasMany(JobGroup::class, 'event_id');
    }

    /**
     * All tasks across every group for this event.
     * Convenience relation so we can quickly list every task for an event.
     */
    public function jobTasks()
    {
        return $this->hasMany(JobTask::class, 'event_id');
    }

    public function vendors()
    {
        return $this->belongsToMany(Vendor::class, 'event_vendors')
            ->withPivot([
                'status',
                'agreed_price',
                'advance_paid',
                'balance_due',
                'service_start',
                'service_end',
                'contract_path',
                'invoice_number',
                'notes'
            ])
            ->withTimestamps();
    }

    /**
     * All post-event feedback submissions left by clients for this event.
     */
    public function feedbacks()
    {
        return $this->hasMany(Feedback::class);
    }

    /* public function services()
    {
        return $this->belongsToMany(Service::class, 'event_service')
                    ->withPivot('value')
                    ->withTimestamps()
                    // Automatically cast the JSON 'value' column to an array
                    ->withCasts(['pivot.value' => 'array']); 
    } */
}
