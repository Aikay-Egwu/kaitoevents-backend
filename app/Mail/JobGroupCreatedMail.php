<?php

namespace App\Mail;

use App\Models\JobGroup;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * JobGroupCreatedMail — dispatched to every group member immediately after
 * a job group is saved.
 *
 * The email body includes:
 *   - Group name, description
 *   - Team Lead (name + email)
 *   - Parent event title, date, venue
 *   - Full list of tasks in the group (all tasks are assigned to the whole
 *     group, so every member sees the full set)
 */
class JobGroupCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @var array<string,mixed>  Pre-serialised view data (member + group + tasks) */
    public $data;

    /**
     * @param JobGroup $group   Freshly saved group with event + members + tasks relations loaded
     * @param User     $member  The recipient — each member gets their own copy
     */
    public function __construct(public JobGroup $group, public User $member)
    {
        $group->loadMissing(['event', 'members', 'teamLead', 'tasks']);

        $event = $group->event;

        // Summary of every task in the group — shown to every member,
        // because tasks are assigned at the group level.
        $tasks = $group->tasks
            ->map(static fn ($t) => [
                'title'            => $t->title,
                'instructions'     => $t->instructions,
                'status'           => $t->status,
                'location'         => $t->location,
                'duration_minutes' => $t->duration_minutes,
            ])
            ->toArray();

        $this->data = [
            'recipient_name' => $member->name,
            'recipient_email' => $member->email,
            'group_name'     => $group->name,
            'group_description' => $group->description,
            'team_lead' => [
                'name'  => $group->teamLead?->name  ?? 'TBD',
                'email' => $group->teamLead?->email ?? '—',
            ],
            'event' => [
                'title'  => $event?->event_name        ?? 'TBD',
                'date'   => $event?->event_date?->toFormattedDateString() ?? 'TBD',
                'venue'  => $event?->venue?->venue_name ?? 'TBD',
            ],
            'member_count' => $group->members->count(),
            'tasks'        => $tasks,
        ];
    }

    /** Subject + Blade body for the notification. */
    public function build()
    {
        return $this
            ->subject("You've been added to the {$this->data['group_name']} team — {$this->data['event']['title']}")
            ->view('emails.job-group-created')
            ->with('data', $this->data);
    }
}
