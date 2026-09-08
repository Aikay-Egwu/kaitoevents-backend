<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Event Usage Log — deployment & return record for an individual inventory item.
 * Mirrors the "🔄 Event Usage Log" tab in the Stock Asset Register Excel.
 */
class EventUsageLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_id',
        'event_id',
        'event_ref',
        'event_name_client',
        'event_date',
        'quantity_deployed',
        'condition_out',
        'condition_back',
        'date_returned',
        'notes_damage_action',
        'user_id',
    ];

    protected $casts = [
        'event_date'         => 'date',
        'date_returned'      => 'date',
        'quantity_deployed'  => 'integer',
    ];

    /**
     * The inventory asset deployed to an event.
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }

    /**
     * Optional: associated real Event (if this log references a booked event).
     * May be NULL for manual entries that reference free-text event_ref/name only.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    /**
     * User (admin/staff) who created the log entry.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Human-readable condition labels — matches Inventory::getConditionLabelAttribute pattern.
     */
    public function getConditionOutLabelAttribute(): ?string
    {
        return $this->conditionOutBackLabel($this->condition_out);
    }

    public function getConditionBackLabelAttribute(): ?string
    {
        return $this->conditionOutBackLabel($this->condition_back);
    }

    private function conditionOutBackLabel(?string $key): ?string
    {
        if ($key === null) return null;

        return match ($key) {
            'excellent_good'       => 'Excellent / Good',
            'fair_wear'            => 'Fair / Wear',
            'needs_repair'         => 'Needs Repair',
            'retired_written_off'  => 'Retired / Written Off',
            default                => $key,
        };
    }
}
