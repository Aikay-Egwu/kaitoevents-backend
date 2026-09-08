<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EventTrackingItem — Event-specific task or item record.
 *
 * Each tracking item belongs to one event and is assigned to one staff user.
 * Category distinguishes action items (task) from physical procurement (item).
 * Status tracks progression through pending → undone → done workflow.
 */
class EventTrackingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'task_description',
        'category',
        'status',
        'additional_notes',
        'assigned_to',
    ];

    protected $casts = [
        // Casts are native strings since enums are stored as VARCHAR in SQLite
    ];

    /**
     * Get the parent event that owns this tracking item.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the staff member assigned to this task/item.
     * References users.id via the assigned_to foreign key.
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Scope to filter items by category (task or item).
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to filter items by status (done, undone, pending).
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter items assigned to a specific staff member.
     */
    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
    }
}
