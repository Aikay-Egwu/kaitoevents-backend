<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * JobTask — a single job/task assigned to a group for a specific event.
 *
 * Field highlights:
 *   - location        enum {on_site, in_house}        — task classification
 *   - duration_minutes int|null                       — optional, configurable estimate
 *   - status          enum {pending, in_progress, completed, blocked}
 */
final class JobTask extends Model
{
    use HasFactory;

    protected $table = 'event_job_tasks';

    protected $fillable = [
        'event_id',
        'event_job_group_id',
        'title',
        'instructions',
        'status',
        'location',
        'duration_minutes',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
    ];

    // ---------------- Relationships ----------------

    /** Parent event (kept for quick filtering on tasks dashboard) */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    /** Group that owns this task (tasks are assigned to whole groups) */
    public function group(): BelongsTo
    {
        return $this->belongsTo(JobGroup::class, 'event_job_group_id');
    }
}
