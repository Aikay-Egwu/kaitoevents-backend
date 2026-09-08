<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * JobGroup — a team/group of staff assigned to a specific event.
 *
 * A group:
 *   - belongs to exactly one Event via event_id
 *   - has exactly one designated Team Lead (User) via team_lead_user_id (nullable)
 *   - has many members (User) through the event_job_group_members pivot
 *   - has many JobTasks (event_job_tasks.event_job_group_id)
 */
final class JobGroup extends Model
{
    use HasFactory;

    protected $table = 'event_job_groups';

    protected $fillable = [
        'event_id',
        'name',
        'team_lead_user_id',
        'description',
    ];

    // ---------------- Relationships ----------------

    /** Parent event that owns this group */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    /** The user designated as team lead for this group */
    public function teamLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_lead_user_id');
    }

    /** All members in this group, with pivot flag `is_team_lead` */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'event_job_group_members',
            'event_job_group_id',
            'user_id',
        )
            ->withPivot('is_team_lead')
            ->withTimestamps();
    }

    /** Only team-lead subset of members (in addition to the direct teamLead relation) */
    public function leadMembers(): BelongsToMany
    {
        return $this->members()->wherePivot('is_team_lead', true);
    }

    /** All tasks assigned to this group */
    public function tasks(): HasMany
    {
        return $this->hasMany(JobTask::class, 'event_job_group_id');
    }
}
