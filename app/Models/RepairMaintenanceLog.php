<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Repair & Maintenance Log — records repair, PAT tests, servicing, and asset retirement.
 * Mirrors the "🔧 Repair & Maintenance Log" tab in the Stock Asset Register Excel.
 *
 * Lifecycle auto-update: when a log is saved with status=completed and a new_condition,
 * the parent inventory item's condition is automatically refreshed (see booted()).
 */
class RepairMaintenanceLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_id',
        'issue_work_required',
        'date_logged',
        'date_resolved',
        'cost',
        'action_taken',
        'repaired_by',
        'technician_user_id',
        'new_condition',
        'status',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'date_logged'    => 'date',
        'date_resolved'  => 'date',
        'cost'           => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // Auto-audit: on save, if status=completed and a new_condition is set,
        // propagate the new condition back to the inventory asset itself.
        static::saved(function (self $log) {
            if (
                $log->status === 'completed'
                && $log->new_condition !== null
                && $log->inventory
                && $log->inventory->condition !== $log->new_condition
            ) {
                $log->inventory->update(['condition' => $log->new_condition]);
            }
        });
    }

    /**
     * Inventory asset being repaired/maintained.
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }

    /**
     * Internal staff user assigned as technician (optional, external = free-text repaired_by).
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_user_id');
    }

    /**
     * Admin/staff member who created this log entry.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Human-readable label for new_condition (matches Inventory pattern).
     */
    public function getNewConditionLabelAttribute(): ?string
    {
        if ($this->new_condition === null) return null;

        return match ($this->new_condition) {
            'excellent_good'       => 'Excellent / Good',
            'fair_wear'            => 'Fair / Wear',
            'needs_repair'         => 'Needs Repair',
            'retired_written_off'  => 'Retired / Written Off',
            default                => $this->new_condition,
        };
    }

    /**
     * Status badge label — used by resources for display-friendly status strings.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending'     => 'Pending',
            'in_progress' => 'In Progress',
            'completed'   => 'Completed',
            'written_off' => 'Written Off',
            default       => ucfirst($this->status),
        };
    }
}
