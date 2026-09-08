<?php

namespace App\Events;

use App\Models\JobGroup;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired immediately after an admin creates a job group and all of its
 * members + initial tasks are persisted to the database.
 *
 * Attached listeners are responsible for side effects such as sending
 * the per-member notification email. The controller guarantees the
 * relations (event, members, teamLead, tasks) are already loaded onto
 * the JobGroup before this event is dispatched.
 */
class JobGroupCreated
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public JobGroup $group;

    public function __construct(JobGroup $group)
    {
        $this->group = $group;
    }
}
